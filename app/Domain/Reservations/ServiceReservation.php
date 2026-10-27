<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Domain\Calendrier\Creneau as CreneauDemande;
use App\Domain\Parametres;
use App\Models\Creneau;
use App\Models\Journal;
use App\Models\Reservation;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Réservation en cinq étapes, dans une seule transaction (RFC, règles métier) :
 * 1. verrouiller les créneaux demandés (SELECT … FOR UPDATE, ADR 006) ;
 * 2. refuser ceux d'une période de vacances, attribuée ou non, et ceux d'une fermeture ;
 * 3. vérifier la règle d'équité ;
 * 4. vérifier les conflits avec les réservations actives (ouverte / exclusive) ;
 * 5. enregistrer, journaliser ; l'e-mail part après la réponse.
 *
 * La modification suit les mêmes étapes en ignorant la réservation modifiée.
 */
final class ServiceReservation
{
    public const PERSONNES_MAX = 50;

    public function __construct(private readonly Parametres $parametres) {}

    /**
     * @param  list<CreneauDemande>  $demandes
     */
    public function reserver(
        User $membre,
        array $demandes,
        string $mode,
        ?string $commentaire,
        DateTimeImmutable $maintenant,
        ?int $nombrePersonnes = null,
        ?string $invites = null,
    ): Reservation {
        $demandes = $this->normaliser($demandes, $mode, $nombrePersonnes);

        return DB::transaction(function () use ($membre, $demandes, $mode, $commentaire, $maintenant, $nombrePersonnes, $invites) {
            $creneaux = $this->verrouiller($demandes);
            $this->verifier($membre, $creneaux, $mode, $maintenant, null);

            $reservation = new Reservation([
                'mode' => $mode, 'commentaire' => $commentaire,
                'nombre_personnes' => $nombrePersonnes, 'invites' => $invites,
            ]);
            $reservation->user_id = $membre->id;
            $reservation->save();
            $reservation->creneaux()->attach($creneaux->pluck('id'));

            Journal::ecrire('reservation.creee', $reservation, [
                'creneaux' => array_map(fn (CreneauDemande $d) => $d->cle(), $demandes),
                'mode' => $mode,
                'nombre_personnes' => $nombrePersonnes,
            ]);

            return $reservation;
        });
    }

    /**
     * US-305 : seul le réservant modifie sa réservation (le contrôle d'accès
     * est fait par la policy avant l'appel). Tout ou rien : un refus laisse
     * la réservation d'origine intacte.
     *
     * @param  list<CreneauDemande>  $demandes
     */
    public function modifier(
        Reservation $reservation,
        array $demandes,
        string $mode,
        ?string $commentaire,
        DateTimeImmutable $maintenant,
        ?int $nombrePersonnes = null,
        ?string $invites = null,
    ): Reservation {
        $demandes = $this->normaliser($demandes, $mode, $nombrePersonnes);

        return DB::transaction(function () use ($reservation, $demandes, $mode, $commentaire, $maintenant, $nombrePersonnes, $invites) {
            /** @var Reservation $verrouillee */
            $verrouillee = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->exigerModifiable($verrouillee, $maintenant);

            $creneaux = $this->verrouiller($demandes);
            /** @var User $membre */
            $membre = $verrouillee->user()->firstOrFail();
            $this->verifier($membre, $creneaux, $mode, $maintenant, $verrouillee);

            $avant = $this->instantane($verrouillee);
            $verrouillee->fill([
                'mode' => $mode, 'commentaire' => $commentaire,
                'nombre_personnes' => $nombrePersonnes, 'invites' => $invites,
            ])->save();
            $verrouillee->creneaux()->sync($creneaux->pluck('id'));

            Journal::ecrire('reservation.modifiee', $verrouillee, ['avant' => $avant, 'apres' => $this->instantane($verrouillee)]);

            return $verrouillee;
        });
    }

    /** US-306 : les créneaux se libèrent, ou restent ouverts s'il reste d'autres participants. */
    public function annuler(Reservation $reservation, DateTimeImmutable $maintenant): void
    {
        DB::transaction(function () use ($reservation, $maintenant) {
            /** @var Reservation $verrouillee */
            $verrouillee = Reservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $this->exigerModifiable($verrouillee, $maintenant);

            $verrouillee->annulee_le = \Illuminate\Support\Carbon::instance($maintenant);
            $verrouillee->save();

            Journal::ecrire('reservation.annulee', $verrouillee, ['avant' => $this->instantane($verrouillee)]);
        });
    }

    /** Une réservation est « passée » quand son dernier créneau est avant aujourd'hui. */
    public function estPassee(Reservation $reservation, DateTimeImmutable $maintenant): bool
    {
        $dernier = $reservation->creneaux()->reorder()->orderByDesc('date')->first();

        return $dernier === null || $dernier->date->format('Y-m-d') < $maintenant->format('Y-m-d');
    }

    /**
     * @param  list<CreneauDemande>  $demandes
     * @return list<CreneauDemande>
     */
    private function normaliser(array $demandes, string $mode, ?int $nombrePersonnes): array
    {
        if ($demandes === []) {
            throw new ReservationRefusee('Choisissez au moins un créneau.');
        }
        if (! in_array($mode, ['ouverte', 'exclusive'], true)) {
            throw new ReservationRefusee('Mode de réservation inconnu.');
        }
        if ($nombrePersonnes !== null && ($nombrePersonnes < 1 || $nombrePersonnes > self::PERSONNES_MAX)) {
            throw new ReservationRefusee('Le nombre de personnes doit être compris entre 1 et '.self::PERSONNES_MAX.'.');
        }

        $uniques = [];
        foreach ($demandes as $d) {
            $uniques[$d->cle()] = $d;
        }
        ksort($uniques);

        return array_values($uniques);
    }

    private function exigerModifiable(Reservation $reservation, DateTimeImmutable $maintenant): void
    {
        if ($reservation->annulee_le !== null) {
            throw new ReservationRefusee('Cette réservation est déjà annulée.');
        }
        if ($this->estPassee($reservation, $maintenant)) {
            throw new ReservationRefusee('Cette réservation est passée : elle ne peut plus être modifiée.');
        }
    }

    /** @param Collection<int, Creneau> $creneaux */
    private function verifier(User $membre, Collection $creneaux, string $mode, DateTimeImmutable $maintenant, ?Reservation $exclue): void
    {
        // 2. Vacances et fermetures
        if ($creneaux->contains(fn (Creneau $c) => $c->periode_id !== null)) {
            throw new ReservationRefusee('Les vacances de Noël et de février ne se réservent pas : elles sont attribuées par les admins.');
        }
        $premier = $creneaux->firstOrFail();
        $dernier = $creneaux->reverse()->firstOrFail();
        $fermee = DB::table('fermetures')
            ->where('debut', '<=', $dernier->date->format('Y-m-d'))
            ->where('fin', '>=', $premier->date->format('Y-m-d'))
            ->exists();
        if ($fermee) {
            throw new ReservationRefusee("L'appartement est fermé sur une partie de ces dates.");
        }

        // 3. Équité (la réservation modifiée ne compte pas contre elle-même)
        $sur12Mois = Reservation::query()
            ->actives()
            ->where('user_id', $membre->id)
            ->where('created_at', '>=', $maintenant->modify('-12 months'))
            ->when($exclue, fn ($q, Reservation $e) => $q->whereKeyNot($e->id))
            ->count();
        $verdict = $this->parametres->regleEquite()->verifier(
            $maintenant,
            DateTimeImmutable::createFromInterface($premier->date),
            $sur12Mois,
        );
        if (! $verdict->accepte) {
            throw new ReservationRefusee((string) $verdict->motif);
        }

        // 4. Conflits
        $existantes = DB::table('reservation_creneau as rc')
            ->join('reservations as r', 'r.id', '=', 'rc.reservation_id')
            ->whereIn('rc.creneau_id', $creneaux->pluck('id'))
            ->whereNull('r.annulee_le')
            ->when($exclue, fn ($q, Reservation $e) => $q->where('r.id', '!=', $e->id))
            ->get(['r.mode', 'r.user_id']);
        if ($existantes->contains('mode', 'exclusive')) {
            throw new ReservationRefusee('Un de ces créneaux est déjà pris en exclusif.');
        }
        if ($existantes->contains('user_id', $membre->id)) {
            throw new ReservationRefusee('Vous êtes déjà inscrit sur un de ces créneaux.');
        }
        if ($mode === 'exclusive' && $existantes->isNotEmpty()) {
            throw new ReservationRefusee($exclue
                ? 'Impossible de passer en exclusif : d\'autres membres sont inscrits sur ces créneaux.'
                : 'Un de ces créneaux est déjà pris : la réservation exclusive n\'est possible que sur des créneaux libres.');
        }
    }

    /** @return array<string, mixed> */
    private function instantane(Reservation $r): array
    {
        return [
            'mode' => $r->mode,
            'nombre_personnes' => $r->nombre_personnes,
            'creneaux' => $r->creneaux()->get()->map(fn (Creneau $c) => $c->date->format('Y-m-d').'-'.$c->type)->all(),
        ];
    }

    /**
     * @param  list<CreneauDemande>  $demandes
     * @return Collection<int, Creneau>
     */
    private function verrouiller(array $demandes): Collection
    {
        $creneaux = Creneau::query()
            ->where(function ($q) use ($demandes) {
                foreach ($demandes as $d) {
                    $q->orWhere(fn ($w) => $w->whereDate('date', $d->date->format('Y-m-d'))->where('type', $d->type->value));
                }
            })
            ->orderBy('date')->orderBy('type')
            ->lockForUpdate()
            ->get();

        // Un créneau absent = année scolaire pas encore ouverte.
        if ($creneaux->count() !== count($demandes)) {
            throw new ReservationRefusee('Ces dates ne sont pas encore ouvertes à la réservation.');
        }

        return $creneaux;
    }
}

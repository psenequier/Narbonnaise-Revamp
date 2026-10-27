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
 */
final class ServiceReservation
{
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
    ): Reservation {
        if ($demandes === []) {
            throw new ReservationRefusee('Choisissez au moins un créneau.');
        }
        if (! in_array($mode, ['ouverte', 'exclusive'], true)) {
            throw new ReservationRefusee('Mode de réservation inconnu.');
        }

        $uniques = [];
        foreach ($demandes as $d) {
            $uniques[$d->cle()] = $d;
        }
        ksort($uniques);
        $demandes = array_values($uniques);

        return DB::transaction(function () use ($membre, $demandes, $mode, $commentaire, $maintenant) {
            // 1. Verrou : l'ordre (date, type) est toujours le même, pas d'interblocage.
            $creneaux = $this->verrouiller($demandes);

            // 2. Vacances et fermetures
            if ($creneaux->contains(fn (Creneau $c) => $c->periode_id !== null)) {
                throw new ReservationRefusee('Les vacances de Noël et de février ne se réservent pas : elles sont attribuées par les admins.');
            }
            $premier = $creneaux->firstOrFail();
            $dernier = $creneaux->reverse()->firstOrFail();
            $fermee = DB::table('fermetures')
                ->where('debut', '<=', $dernier->date)
                ->where('fin', '>=', $premier->date)
                ->exists();
            if ($fermee) {
                throw new ReservationRefusee("L'appartement est fermé sur une partie de ces dates.");
            }

            // 3. Équité
            $sur12Mois = Reservation::query()
                ->actives()
                ->where('user_id', $membre->id)
                ->where('created_at', '>=', $maintenant->modify('-12 months'))
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
                ->pluck('r.mode');
            if ($existantes->contains('exclusive') || ($mode === 'exclusive' && $existantes->isNotEmpty())) {
                throw new ReservationRefusee('Un de ces créneaux est déjà pris.');
            }

            // 5. Enregistrement
            $reservation = new Reservation(['mode' => $mode, 'commentaire' => $commentaire]);
            $reservation->user_id = $membre->id;
            $reservation->save();
            $reservation->creneaux()->attach($creneaux->pluck('id'));

            Journal::ecrire('reservation.creee', $reservation, [
                'creneaux' => array_map(fn (CreneauDemande $d) => $d->cle(), $demandes),
                'mode' => $mode,
            ]);

            return $reservation;
        });
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
            throw new ReservationRefusee("Ces dates ne sont pas encore ouvertes à la réservation.");
        }

        return $creneaux;
    }
}

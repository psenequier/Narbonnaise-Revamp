<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use App\Domain\Emails\BoiteEnvoi;
use App\Models\Creneau;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * E-mails d'une réservation (ADR 009) :
 * - au réservant, sa confirmation avec le .ics (US-501), ou l'action d'un admin (US-309) ;
 * - à chaque membre inscrit sur les mêmes créneaux, un message individuel (US-502) ;
 * - aux autres membres qui le souhaitent, une information groupée en copie cachée (US-503).
 * Aucun e-mail ne contient l'adresse d'un autre membre, ni un lien utilisable sans connexion.
 */
final class AvisReservation
{
    public function __construct(private readonly BoiteEnvoi $boite) {}

    public function apresCreation(Reservation $r): void
    {
        $r->refresh();
        $r->load('creneaux', 'user');
        $donnees = $this->donnees($r);

        $this->boite->deposer('reservation.confirmation', [$this->adresse($r)], [], $donnees + ['ics' => $this->ics($r)]);
        $prevenus = $this->prevenirParticipants($r, $r->creneaux->pluck('id')->all(), 'joint', $r->user_id);
        $this->informer($r, 'creee', [$r->user_id, ...$prevenus]);

        $this->boite->envoyer();
    }

    /** @param list<int> $creneauxAvant */
    public function apresModification(Reservation $r, array $creneauxAvant, ?User $admin = null, ?string $motif = null): void
    {
        $r->increment('ics_sequence');
        $r->refresh();
        $r->load('creneaux', 'user');
        $donnees = $this->donnees($r) + ['ics' => $this->ics($r)];

        if ($admin !== null) {
            $this->boite->deposer('reservation.action_admin', [$this->adresse($r)], [], $donnees + [
                'action' => 'modifié', 'admin' => $admin->prenom.' '.$admin->nom, 'motif' => $motif,
            ]);
        } else {
            $this->boite->deposer('reservation.modifiee', [$this->adresse($r)], [], $donnees);
        }
        $creneaux = array_values(array_unique([...$creneauxAvant, ...$r->creneaux->pluck('id')->all()]));
        $this->prevenirParticipants($r, $creneaux, 'modifié', $admin->id ?? $r->user_id);

        $this->boite->envoyer();
    }

    public function apresAnnulation(Reservation $r, ?User $admin = null, ?string $motif = null): void
    {
        $r->refresh();
        $r->load('creneaux', 'user');
        $donnees = $this->donnees($r) + ['ics' => $this->ics($r, annule: true)];

        if ($admin !== null) {
            $this->boite->deposer('reservation.action_admin', [$this->adresse($r)], [], $donnees + [
                'action' => 'annulé', 'admin' => $admin->prenom.' '.$admin->nom, 'motif' => $motif,
            ]);
        } else {
            $this->boite->deposer('reservation.annulee', [$this->adresse($r)], [], $donnees);
        }
        $prevenus = $this->prevenirParticipants($r, $r->creneaux->pluck('id')->all(), 'annulé', $admin->id ?? $r->user_id);
        $this->informer($r, 'annulee', [$r->user_id, ...$prevenus, ...($admin ? [$admin->id] : [])]);

        $this->boite->envoyer();
    }

    /**
     * US-502 : un message individuel par membre inscrit sur ces créneaux, sauf l'auteur.
     * Aucune préférence ne le désactive (US-502-N2).
     *
     * @param  list<int>  $creneaux
     * @return list<int> les membres prévenus
     */
    private function prevenirParticipants(Reservation $r, array $creneaux, string $action, int $auteur): array
    {
        $membres = User::query()
            ->where('actif', true)
            ->whereKeyNot($auteur)
            ->whereKeyNot($r->user_id)
            ->whereIn('id', DB::table('reservations')
                ->join('reservation_creneau as rc', 'rc.reservation_id', '=', 'reservations.id')
                ->whereIn('rc.creneau_id', $creneaux)
                ->whereNull('reservations.annulee_le')
                ->where('reservations.id', '!=', $r->id)
                ->select('reservations.user_id'))
            ->get(['id', 'email']);

        foreach ($membres as $membre) {
            $this->boite->deposer('reservation.mouvement', [$membre->email], [], $this->donnees($r) + ['action' => $action]);
        }

        return $membres->pluck('id')->all();
    }

    /**
     * US-503 : une seule information, en copie cachée, aux membres qui la souhaitent.
     *
     * @param  list<int>  $exclus
     */
    private function informer(Reservation $r, string $action, array $exclus): void
    {
        $cci = User::query()->recoitReservationsDesAutres()->whereNotIn('id', $exclus)->pluck('email')->values()->all();
        if ($cci === []) {
            return;
        }

        $this->boite->deposer('reservation.information', [], $cci, $this->donnees($r) + ['action' => $action], BoiteEnvoi::INFORMATION);
    }

    /** @return array<string, mixed> */
    private function donnees(Reservation $r): array
    {
        $premier = $r->creneaux->first();

        return [
            'reservation' => $r->id,
            'reservant' => trim(($r->user->prenom ?? '').' '.($r->user->nom ?? '')),
            'mode' => $r->mode === 'exclusive' ? 'Exclusive' : 'Ouverte',
            'nombre_personnes' => $r->nombre_personnes,
            'commentaire' => $r->mode === 'ouverte' ? $r->commentaire : null,
            'creneaux' => $r->creneaux->map(fn (Creneau $c) => self::libelle($c))->all(),
            'periode' => self::periode($r),
            'lien' => $premier ? route('jour', $premier->date->format('Y-m-d')) : route('calendrier'),
        ];
    }

    /** @return array<string, mixed> */
    private function ics(Reservation $r, bool $annule = false): array
    {
        $premier = $r->creneaux->first();
        $dernier = $r->creneaux->last();
        $debut = $premier?->date->format('Y-m-d') ?? now()->format('Y-m-d');
        $fin = $dernier ? $dernier->date->copy()->addDays($dernier->type === 'N' ? 2 : 1)->format('Y-m-d') : $debut;

        return [
            'uid' => 'reservation-'.$r->id.'@'.(parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'chamrousse'),
            'sequence' => (int) $r->ics_sequence,
            'debut' => $debut,
            'fin' => $fin,
            'titre' => 'Chamrousse : séjour à l\'appartement',
            'description' => self::periode($r)."\n".$r->creneaux->map(fn (Creneau $c) => self::libelle($c))->implode("\n"),
            'annule' => $annule,
        ];
    }

    private function adresse(Reservation $r): string
    {
        return (string) $r->user?->email;
    }

    public static function libelle(Creneau $c): string
    {
        return Carbon::parse($c->date->format('Y-m-d'))->locale('fr')->translatedFormat('D j/m').' '.($c->type === 'N' ? 'nuit' : 'jour');
    }

    private static function periode(Reservation $r): string
    {
        $p = $r->creneaux->first();
        $d = $r->creneaux->last();

        return $p && $d ? 'Du '.self::libelle($p).' au '.self::libelle($d) : '';
    }
}

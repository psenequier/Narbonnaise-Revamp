<?php

declare(strict_types=1);

namespace App\Domain\Vacances;

use App\Domain\Emails\BoiteEnvoi;
use App\Domain\Reservations\AvisReservation;
use App\Domain\Reservations\ServiceReservation;
use App\Models\Journal;
use App\Models\Reservation;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * US-407 : une réservation rattrapée par de nouvelles dates de vacances passe
 * « En conflit ». Rien n'est annulé automatiquement : un admin arbitre, avec un motif.
 */
final class Conflits
{
    public function __construct(
        private readonly AnnoncesVacances $annonces,
        private readonly ServiceReservation $service,
        private readonly AvisReservation $avis,
        private readonly BoiteEnvoi $boite,
    ) {}

    /**
     * Marque les réservations actives sur ces créneaux et alerte les admins.
     *
     * @param  list<int>  $creneaux
     * @return int nombre de réservations nouvellement en conflit
     */
    public function detecter(array $creneaux): int
    {
        if ($creneaux === []) {
            return 0;
        }
        $reservations = Reservation::query()
            ->actives()
            ->whereNull('en_conflit_le')->whereNull('conflit_maintenu_le')
            ->whereIn('id', DB::table('reservation_creneau')->whereIn('creneau_id', $creneaux)->select('reservation_id'))
            ->with('user:id,prenom,nom', 'creneaux')
            ->get();
        if ($reservations->isEmpty()) {
            return 0;
        }

        Reservation::query()->whereKey($reservations->modelKeys())->update(['en_conflit_le' => now()]);
        foreach ($reservations as $r) {
            Journal::ecrire('reservation.en_conflit', $r);
        }
        $this->annonces->alerterAdmins(
            'admin.conflits_vacances',
            'De nouvelles dates de vacances englobent des réservations existantes. Aucune n\'a été annulée : arbitrez chacune depuis l\'écran Vacances.',
            array_values($reservations->map(fn (Reservation $r) => 'Réservation n° '.$r->id.' de '.$r->user?->prenom.' '.$r->user?->nom
                .', du '.$r->creneaux->first()?->date->format('d/m/Y').' au '.$r->creneaux->last()?->date->format('d/m/Y'))->all()),
        );

        return $reservations->count();
    }

    /** US-407-P2 : maintenir ou annuler, motif obligatoire ; le réservant est prévenu. */
    public function arbitrer(Reservation $reservation, string $decision, string $motif, User $admin): void
    {
        if (trim($motif) === '') {
            throw new RefusVacances('Indiquez le motif de l\'arbitrage : il est envoyé au membre.');
        }
        if ($reservation->en_conflit_le === null) {
            throw new RefusVacances('Cette réservation n\'est pas en conflit.');
        }

        if ($decision === 'annuler') {
            $this->service->annuler($reservation, new DateTimeImmutable, $motif);
            $reservation->forceFill(['en_conflit_le' => null])->save();
            $this->avis->apresAnnulation($reservation, $admin, $motif);

            return;
        }
        if ($decision !== 'maintenir') {
            throw new RefusVacances('Décision inconnue.');
        }

        $reservation->forceFill(['en_conflit_le' => null, 'conflit_maintenu_le' => now()])->save();
        Journal::ecrire('reservation.conflit_maintenu', $reservation, ['motif_admin' => $motif]);
        $reservation->load('creneaux', 'user');
        $this->boite->deposer('reservation.action_admin', [(string) $reservation->user?->email], [], [
            'action' => 'maintenu, malgré les nouvelles dates de vacances,',
            'admin' => $admin->prenom.' '.$admin->nom,
            'motif' => $motif,
            'periode' => 'Du '.AvisReservation::libelle($reservation->creneaux->firstOrFail()).' au '.AvisReservation::libelle($reservation->creneaux->last() ?? $reservation->creneaux->firstOrFail()),
            'creneaux' => $reservation->creneaux->map(fn ($c) => AvisReservation::libelle($c))->all(),
            'mode' => $reservation->mode === 'exclusive' ? 'Exclusive' : 'Ouverte',
            'nombre_personnes' => $reservation->nombre_personnes,
            'commentaire' => null,
            'lien' => route('jour', $reservation->creneaux->firstOrFail()->date->format('Y-m-d')),
        ]);
        $this->boite->envoyer();
    }
}

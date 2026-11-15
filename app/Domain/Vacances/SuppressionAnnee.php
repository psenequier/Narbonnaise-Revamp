<?php

declare(strict_types=1);

namespace App\Domain\Vacances;

use App\Models\AnneeScolaire;
use App\Models\Attribution;
use App\Models\Journal;
use App\Models\Reservation;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * US-409 (décision du 7 nov. 2026) : suppression d'une année scolaire créée à la main par
 * erreur ; masquage d'une saison ouverte pas encore commencée (décision du 15 nov. 2026). Jamais une année du calendrier officiel (la relecture la recréerait), jamais une
 * année commencée ou passée, jamais une année avec une réservation active ou une attribution.
 */
final class SuppressionAnnee
{
    /** Raison du refus, ou null si l'année peut être supprimée. */
    public function refus(AnneeScolaire $annee, DateTimeImmutable $aujourdhui): ?string
    {
        if (! $annee->creee_a_la_main) {
            return 'Saison créée d\'après le calendrier officiel : elle ne se supprime pas, corrigez ses dates si besoin.';
        }
        if ($annee->debut->format('Y-m-d') <= $aujourdhui->format('Y-m-d')) {
            return 'Saison commencée ou passée : elle ne se supprime pas.';
        }

        return $this->occupee($annee);
    }

    /**
     * Décision du 15 nov. 2026 : raison du refus de masquer une saison, ou null si elle peut l'être.
     * Mêmes garde-fous que la suppression, mais aussi pour une saison du calendrier officiel.
     */
    public function refusMasquage(AnneeScolaire $annee, DateTimeImmutable $aujourdhui): ?string
    {
        if (! $annee->ouverte) {
            return 'Saison déjà fermée à la réservation.';
        }
        if ($annee->debut->format('Y-m-d') <= $aujourdhui->format('Y-m-d')) {
            return 'Saison commencée ou passée : elle ne se masque pas.';
        }

        return $this->occupee($annee);
    }

    /**
     * Ferme une saison pas encore commencée : ses créneaux disparaissent du calendrier (« pas encore
     * ouvert »), ses vacances restent connues. La relecture du calendrier officiel ne la rouvre plus :
     * un admin la rouvre en saisissant les dates de Noël et de février.
     */
    public function masquer(AnneeScolaire $annee, User $admin, DateTimeImmutable $aujourdhui): void
    {
        DB::transaction(function () use ($annee, $admin, $aujourdhui) {
            $annee = AnneeScolaire::query()->lockForUpdate()->findOrFail($annee->id);
            $refus = $this->refusMasquage($annee, $aujourdhui);
            if ($refus !== null) {
                throw new RefusVacances($refus);
            }
            $this->retirerCreneaux($annee);
            $annee->forceFill(['ouverte' => false, 'masquee' => true, 'ouverte_le' => null, 'ouverte_par' => null])->save();
            Journal::ecrire('annee.masquee', $annee, ['libelle' => $annee->libelle], $admin->id);
        });
    }

    /** Réservations actives et créneaux attribués de la saison, ou null s'il n'y en a aucun. */
    private function occupee(AnneeScolaire $annee): ?string
    {
        $creneaux = $this->creneaux($annee);
        $reservations = Reservation::query()->actives()
            ->whereHas('creneaux', fn ($q) => $q->whereIn('creneaux.id', $creneaux))->count();
        $attribues = Attribution::query()->whereIn('creneau_id', $creneaux)->count();
        if ($reservations === 0 && $attribues === 0) {
            return null;
        }
        $morceaux = [];
        if ($reservations > 0) {
            $morceaux[] = $reservations.' '.($reservations > 1 ? 'réservations' : 'réservation');
        }
        if ($attribues > 0) {
            $morceaux[] = $attribues.' '.($attribues > 1 ? 'créneaux attribués' : 'créneau attribué');
        }

        return ucfirst(implode(' et ', $morceaux)).' : annulez-les ou retirez-les d\'abord.';
    }

    public function supprimer(AnneeScolaire $annee, User $admin, DateTimeImmutable $aujourdhui): void
    {
        DB::transaction(function () use ($annee, $admin, $aujourdhui) {
            $annee = AnneeScolaire::query()->lockForUpdate()->findOrFail($annee->id);
            $refus = $this->refus($annee, $aujourdhui);
            if ($refus !== null) {
                throw new RefusVacances($refus);
            }
            $this->retirerCreneaux($annee);
            DB::table('periodes_vacances')->whereIn('id', $annee->periodes()->pluck('id'))->delete();

            Journal::ecrire('annee.supprimee', $annee, [
                'libelle' => $annee->libelle,
                'debut' => $annee->debut->format('Y-m-d'),
                'fin' => $annee->fin->format('Y-m-d'),
                'ouverte' => $annee->ouverte,
            ], $admin->id);
            $annee->delete();
        });
    }

    /** Retire les créneaux de la saison, ses plages et les réservations annulées de ces dates. */
    private function retirerCreneaux(AnneeScolaire $annee): void
    {
        $creneaux = $this->creneaux($annee);
        // Les réservations annulées de ces dates n'ont plus d'objet : elles partent avec leurs créneaux.
        $annulees = DB::table('reservation_creneau')->whereIn('creneau_id', $creneaux)->distinct()->pluck('reservation_id');
        if (DB::table('reservation_creneau')->whereIn('reservation_id', $annulees)->whereNotIn('creneau_id', $creneaux)->exists()) {
            throw new RefusVacances('Une réservation annulée déborde sur la saison voisine : la saison ne peut pas être retirée du calendrier.');
        }
        DB::table('reservations')->whereIn('id', $annulees)->delete();
        DB::table('plages')->whereIn('periode_id', $annee->periodes()->pluck('id'))->delete();
        DB::table('creneaux')->whereIn('id', $creneaux)->delete();
    }

    /** @return list<int> */
    private function creneaux(AnneeScolaire $annee): array
    {
        return array_values(array_map('intval', DB::table('creneaux')
            ->whereBetween('date', [$annee->debut->format('Y-m-d'), $annee->fin->format('Y-m-d')])
            ->pluck('id')->all()));
    }
}

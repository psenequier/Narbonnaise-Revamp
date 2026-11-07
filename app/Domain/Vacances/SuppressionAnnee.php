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
 * erreur. Jamais une année du calendrier officiel (la relecture la recréerait), jamais une
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
            $creneaux = $this->creneaux($annee);
            // Les réservations annulées de ces dates n'ont plus d'objet : elles partent avec leurs créneaux.
            $annulees = DB::table('reservation_creneau')->whereIn('creneau_id', $creneaux)->distinct()->pluck('reservation_id');
            if (DB::table('reservation_creneau')->whereIn('reservation_id', $annulees)->whereNotIn('creneau_id', $creneaux)->exists()) {
                throw new RefusVacances('Une réservation annulée déborde sur la saison voisine : la saison ne peut pas être supprimée.');
            }
            DB::table('reservations')->whereIn('id', $annulees)->delete();
            $periodes = $annee->periodes()->pluck('id');
            DB::table('plages')->whereIn('periode_id', $periodes)->delete();
            DB::table('creneaux')->whereIn('id', $creneaux)->delete();
            DB::table('periodes_vacances')->whereIn('id', $periodes)->delete();

            Journal::ecrire('annee.supprimee', $annee, [
                'libelle' => $annee->libelle,
                'debut' => $annee->debut->format('Y-m-d'),
                'fin' => $annee->fin->format('Y-m-d'),
                'ouverte' => $annee->ouverte,
            ], $admin->id);
            $annee->delete();
        });
    }

    /** @return list<int> */
    private function creneaux(AnneeScolaire $annee): array
    {
        return array_values(array_map('intval', DB::table('creneaux')
            ->whereBetween('date', [$annee->debut->format('Y-m-d'), $annee->fin->format('Y-m-d')])
            ->pluck('id')->all()));
    }
}

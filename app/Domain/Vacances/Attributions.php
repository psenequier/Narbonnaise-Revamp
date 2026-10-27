<?php

declare(strict_types=1);

namespace App\Domain\Vacances;

use App\Domain\Calendrier\Creneau as CreneauDemande;
use App\Models\Attribution;
use App\Models\Creneau;
use App\Models\Journal;
use App\Models\Lignee;
use App\Models\PeriodeVacances;
use App\Models\Plage;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * US-403, US-404 : plages de créneaux de vacances attribuées à une lignée.
 * Une plage reste dans sa période, ne chevauche aucune autre, et tout est
 * vérifié dans une transaction avec verrou. Chaque changement est annoncé (US-504).
 */
final class Attributions
{
    public function __construct(private readonly AnnoncesVacances $annonces) {}

    public function attribuer(PeriodeVacances $periode, int $ligneeId, CreneauDemande $debut, CreneauDemande $fin, User $admin): Plage
    {
        $plage = DB::transaction(function () use ($periode, $ligneeId, $debut, $fin, $admin) {
            $creneaux = $this->creneauxLibres($periode, $ligneeId, $debut, $fin, null);

            $plage = new Plage([
                'periode_id' => $periode->id, 'lignee_id' => $ligneeId,
                'debut' => $debut->date->format('Y-m-d'), 'debut_type' => $debut->type->value,
                'fin' => $fin->date->format('Y-m-d'), 'fin_type' => $fin->type->value,
            ]);
            $plage->saisie_par = $admin->id;
            $plage->save();
            $this->poser($plage, $creneaux, $admin);
            Journal::ecrire('plage.attribuee', $plage, ['lignee' => $ligneeId, 'creneaux' => $creneaux->count()]);

            return $plage;
        }, attempts: 3);

        $this->annonces->recapitulatif($periode->annee, 'attribution');

        return $plage;
    }

    /** US-404-P2, N2 : seuls les créneaux concernés changent ; un chevauchement laisse la plage intacte. */
    public function modifier(Plage $plage, int $ligneeId, CreneauDemande $debut, CreneauDemande $fin, User $admin): Plage
    {
        DB::transaction(function () use ($plage, $ligneeId, $debut, $fin, $admin) {
            $creneaux = $this->creneauxLibres($plage->periode, $ligneeId, $debut, $fin, $plage);
            $avant = $plage->only(['lignee_id', 'debut', 'debut_type', 'fin', 'fin_type']);

            Attribution::query()->where('plage_id', $plage->id)->delete();
            $plage->fill([
                'lignee_id' => $ligneeId,
                'debut' => $debut->date->format('Y-m-d'), 'debut_type' => $debut->type->value,
                'fin' => $fin->date->format('Y-m-d'), 'fin_type' => $fin->type->value,
            ])->save();
            $this->poser($plage, $creneaux, $admin);
            Journal::ecrire('plage.modifiee', $plage, ['avant' => $avant, 'creneaux' => $creneaux->count()]);
        }, attempts: 3);

        $this->annonces->recapitulatif($plage->periode->annee, 'modification');

        return $plage;
    }

    /** US-404-P1 : les créneaux repassent « En attente d'attribution » (toujours bloqués). */
    public function retirer(Plage $plage, User $admin): void
    {
        $annee = $plage->periode->annee;
        DB::transaction(function () use ($plage) {
            Journal::ecrire('plage.retiree', $plage, $plage->only(['lignee_id', 'debut', 'debut_type', 'fin', 'fin_type']));
            Attribution::query()->where('plage_id', $plage->id)->delete();
            $plage->delete();
        });

        $this->annonces->recapitulatif($annee, 'retrait');
    }

    /** @return Collection<int, Creneau> */
    private function creneauxLibres(PeriodeVacances $periode, int $ligneeId, CreneauDemande $debut, CreneauDemande $fin, ?Plage $exclue): Collection
    {
        if (self::rang($fin) < self::rang($debut)) {
            throw new RefusVacances('La fin de la plage précède son début.');
        }
        if (! Lignee::query()->whereKey($ligneeId)->exists()) {
            throw new RefusVacances('Lignée inconnue.');
        }

        $attendus = 0;
        for ($c = $debut; self::rang($c) <= self::rang($fin); $c = self::suivant($c)) {
            $attendus++;
        }

        $creneaux = Creneau::query()
            ->whereBetween('date', [$debut->date->format('Y-m-d'), $fin->date->format('Y-m-d')])
            ->orderBy('date')->orderBy('type')
            ->lockForUpdate()
            ->get()
            ->filter(fn (Creneau $c) => self::rangStocke($c) >= self::rang($debut) && self::rangStocke($c) <= self::rang($fin))
            ->values();

        if ($creneaux->count() !== $attendus || $creneaux->contains(fn (Creneau $c) => $c->periode_id !== $periode->id)) {
            throw new RefusVacances('La plage déborde de la période de vacances '.$periode->libelle().'.');
        }

        $prises = Attribution::query()
            ->whereIn('creneau_id', $creneaux->pluck('id'))
            ->when($exclue, fn ($q, Plage $p) => $q->where(fn ($w) => $w->whereNull('plage_id')->orWhere('plage_id', '!=', $p->id)))
            ->exists();
        if ($prises) {
            throw new RefusVacances('Cette plage chevauche une plage déjà attribuée.');
        }

        return $creneaux;
    }

    /** @param Collection<int, Creneau> $creneaux */
    private function poser(Plage $plage, Collection $creneaux, User $admin): void
    {
        Attribution::query()->insert($creneaux->map(fn (Creneau $c) => [
            'creneau_id' => $c->id, 'lignee_id' => $plage->lignee_id, 'plage_id' => $plage->id,
            'saisie_par' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ])->all());
    }

    /** Ordre des créneaux : le jour (J) puis la nuit (N) de chaque date. */
    private static function rang(CreneauDemande $c): string
    {
        return $c->date->format('Y-m-d').($c->type->value === 'J' ? '0' : '1');
    }

    private static function rangStocke(Creneau $c): string
    {
        return $c->date->format('Y-m-d').($c->type === 'J' ? '0' : '1');
    }

    private static function suivant(CreneauDemande $c): CreneauDemande
    {
        return $c->type->value === 'J'
            ? new CreneauDemande($c->date, \App\Domain\Calendrier\TypeCreneau::Nuit)
            : new CreneauDemande($c->date->modify('+1 day'), \App\Domain\Calendrier\TypeCreneau::Jour);
    }
}

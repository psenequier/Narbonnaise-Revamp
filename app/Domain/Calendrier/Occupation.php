<?php

declare(strict_types=1);

namespace App\Domain\Calendrier;

use App\Models\Creneau as CreneauStocke;
use App\Models\Reservation;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * État de chaque créneau sur une plage de dates, en trois requêtes quelle
 * que soit la durée (mois, saison) : créneaux, réservations actives, fermetures.
 */
final class Occupation
{
    /** @return array<string, SituationCreneau> indexé par « Y-m-d-T » */
    public function entre(DateTimeImmutable $debut, DateTimeImmutable $fin): array
    {
        $du = $debut->format('Y-m-d');
        $au = $fin->format('Y-m-d');

        $stockes = CreneauStocke::query()
            ->with(['attribution.lignee'])
            ->whereBetween('date', [$du, $au])
            ->get()
            ->keyBy(fn (CreneauStocke $c) => $c->date->format('Y-m-d').'-'.$c->type);

        /** @var array<int, list<Reservation>> $parCreneau */
        $parCreneau = [];
        Reservation::query()
            ->actives()
            ->with('user:id,prenom,nom')
            ->join('reservation_creneau as rc', 'rc.reservation_id', '=', 'reservations.id')
            ->whereIn('rc.creneau_id', $stockes->pluck('id'))
            ->orderBy('reservations.created_at')
            ->get(['reservations.*', 'rc.creneau_id as creneau_lie'])
            ->each(function (Reservation $r) use (&$parCreneau) {
                $parCreneau[(int) $r->getAttribute('creneau_lie')][] = $r;
            });

        $fermetures = DB::table('fermetures')->where('debut', '<=', $au)->where('fin', '>=', $du)->get(['debut', 'fin']);

        $situations = [];
        for ($jour = $debut->setTime(0, 0); $jour <= $fin; $jour = $jour->modify('+1 day')) {
            $date = $jour->format('Y-m-d');
            $ferme = $fermetures->contains(fn ($f) => $f->debut <= $date && $f->fin >= $date);

            foreach (TypeCreneau::cases() as $type) {
                $creneau = new Creneau($jour, $type);
                $stocke = $stockes->get($creneau->cle());
                $situations[$creneau->cle()] = $this->situation($creneau, $stocke, $ferme, $stocke ? ($parCreneau[$stocke->id] ?? []) : []);
            }
        }

        return $situations;
    }

    public function du(Creneau $creneau): SituationCreneau
    {
        return $this->entre($creneau->date, $creneau->date)[$creneau->cle()];
    }

    /** @param list<Reservation> $reservations */
    private function situation(Creneau $creneau, ?CreneauStocke $stocke, bool $ferme, array $reservations): SituationCreneau
    {
        if ($stocke === null) {
            return new SituationCreneau($creneau, EtatCreneau::PasOuvert);
        }
        if ($stocke->attribution !== null) {
            $lignee = $stocke->attribution->lignee;

            return new SituationCreneau($creneau, EtatCreneau::Attribue, $lignee?->nom, $lignee?->couleur);
        }
        if ($stocke->periode_id !== null) {
            return new SituationCreneau($creneau, EtatCreneau::Attente);
        }
        if ($ferme) {
            return new SituationCreneau($creneau, EtatCreneau::Ferme);
        }
        if ($reservations === []) {
            return new SituationCreneau($creneau, EtatCreneau::Libre);
        }
        $exclusive = array_filter($reservations, fn (Reservation $r) => $r->mode === 'exclusive') !== [];

        return new SituationCreneau($creneau, $exclusive ? EtatCreneau::Exclusive : EtatCreneau::Ouverte, reservations: $reservations);
    }
}

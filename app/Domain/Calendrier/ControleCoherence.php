<?php

declare(strict_types=1);

namespace App\Domain\Calendrier;

use Illuminate\Support\Facades\DB;

/**
 * Contrôle quotidien (US-912, ADR 006) : signale, sans rien corriger, les
 * créneaux à venir dont les données se contredisent.
 */
final class ControleCoherence
{
    /** @return list<string> une phrase par anomalie */
    public function anomalies(): array
    {
        $depuis = now('Europe/Paris')->format('Y-m-d');
        $actives = fn () => DB::table('reservation_creneau as rc')
            ->join('reservations as r', 'r.id', '=', 'rc.reservation_id')
            ->join('creneaux as c', 'c.id', '=', 'rc.creneau_id')
            ->whereNull('r.annulee_le')
            ->where('c.date', '>=', $depuis);

        $anomalies = [];

        // 1. Un créneau exclusif partagé avec une autre réservation.
        foreach ($actives()->groupBy('c.id', 'c.date', 'c.type')
            ->havingRaw("SUM(r.mode = 'exclusive') >= 1 AND COUNT(*) > 1")
            ->get(['c.date', 'c.type', DB::raw('COUNT(*) as n')]) as $c) {
            $anomalies[] = "{$c->date} {$c->type} : créneau exclusif partagé par {$c->n} réservations.";
        }

        // 2. Une réservation sur des vacances (attribuées ou non).
        foreach ($actives()->whereNotNull('c.periode_id')->get(['c.date', 'c.type', 'r.id']) as $c) {
            $anomalies[] = "{$c->date} {$c->type} : la réservation n° {$c->id} occupe un créneau de vacances.";
        }

        // 3. Un même membre inscrit deux fois sur un créneau.
        foreach ($actives()->groupBy('c.id', 'c.date', 'c.type', 'r.user_id')->havingRaw('COUNT(*) > 1')
            ->get(['c.date', 'c.type', 'r.user_id']) as $c) {
            $anomalies[] = "{$c->date} {$c->type} : le membre n° {$c->user_id} y a plusieurs réservations.";
        }

        // 4. Une réservation pendant une fermeture.
        foreach ($actives()->join('fermetures as f', fn ($j) => $j->on('c.date', '>=', 'f.debut')->on('c.date', '<=', 'f.fin'))
            ->get(['c.date', 'c.type', 'r.id']) as $c) {
            $anomalies[] = "{$c->date} {$c->type} : la réservation n° {$c->id} tombe pendant une fermeture.";
        }

        sort($anomalies);

        return $anomalies;
    }
}

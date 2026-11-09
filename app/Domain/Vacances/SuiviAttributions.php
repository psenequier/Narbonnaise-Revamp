<?php

declare(strict_types=1);

namespace App\Domain\Vacances;

use Illuminate\Support\Facades\DB;

/** US-406 : créneaux de vacances encore sans lignée (le cumul US-405 est retiré le 7 nov. 2026). */
final class SuiviAttributions
{
    /** Créneaux de vacances à venir sans lignée. */
    public function restants(): int
    {
        return DB::table('creneaux as c')
            ->leftJoin('attributions as a', 'a.creneau_id', '=', 'c.id')
            ->whereNotNull('c.periode_id')
            ->whereNull('a.id')
            ->where('c.date', '>=', now('Europe/Paris')->format('Y-m-d'))
            ->count();
    }
}

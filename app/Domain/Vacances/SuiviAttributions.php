<?php

declare(strict_types=1);

namespace App\Domain\Vacances;

use App\Models\Lignee;
use Illuminate\Support\Facades\DB;

/** US-405 (cumul par lignée) et US-406 (créneaux de vacances encore sans lignée). */
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

    /**
     * Total des créneaux attribués à chaque lignée, toutes années confondues
     * (depuis la première année reprise de GRR). Aucun nom de personne.
     *
     * @return list<array{lignee: string, couleur: string, total: int}>
     */
    public function cumul(): array
    {
        $totaux = DB::table('attributions')->groupBy('lignee_id')->pluck(DB::raw('COUNT(*)'), 'lignee_id');

        return array_values(Lignee::query()->orderBy('nom')->get()
            ->map(fn (Lignee $l) => ['lignee' => $l->nom, 'couleur' => $l->couleur, 'total' => (int) ($totaux[$l->id] ?? 0)])
            ->all());
    }
}

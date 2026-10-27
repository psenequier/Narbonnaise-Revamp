<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * /sante : appelée par le test de fumée du déploiement.
 * Ne renvoie que des booléens, aucune version ni chemin (A02).
 */
final class SanteController
{
    public function __invoke(): JsonResponse
    {
        $controles = [
            'base' => $this->essayer(fn () => DB::select('select 1')),
            'cache' => $this->essayer(fn () => Cache::put('sante', 1, 10)),
            // La cron horaire écrit cette valeur ; au-delà de 2 h, elle est en retard.
            'planificateur' => now()->diffInMinutes(Cache::get('planificateur.dernier_passage', now()->subYear())) <= 120,
        ];

        $ok = ! in_array(false, $controles, true);

        return response()->json($controles, $ok ? 200 : 503);
    }

    /** @param callable(): mixed $f */
    private function essayer(callable $f): bool
    {
        try {
            $f();

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}

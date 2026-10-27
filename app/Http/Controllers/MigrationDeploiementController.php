<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Pas de SSH sur l'offre Starter : les migrations passent par cette route.
 * Elle n'existe que si le fichier verrou déposé par le script de déploiement
 * est présent, exige le jeton à usage unique, et supprime le verrou après usage.
 */
final class MigrationDeploiementController
{
    public const VERROU = 'deploiement/migration.verrou';

    public function __invoke(Request $request): JsonResponse
    {
        $verrou = storage_path('app/'.self::VERROU);
        abort_unless(is_file($verrou), 404);

        $attendu = trim((string) file_get_contents($verrou));
        $fourni = (string) $request->bearerToken();

        // Le verrou contient l'empreinte SHA-256 du jeton, jamais le jeton lui-même.
        if ($attendu === '' || ! hash_equals($attendu, hash('sha256', $fourni))) {
            abort(403);
        }

        unlink($verrou); // usage unique, même en cas d'échec de la migration

        $code = Artisan::call('migrate', ['--force' => true]);

        return response()->json(['migre' => $code === 0, 'sortie' => $code === 0 ? null : 'voir les journaux'], $code === 0 ? 200 : 500);
    }
}

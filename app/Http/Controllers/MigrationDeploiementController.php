<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reprise\ControleReprise;
use App\Domain\Reprise\ExportBascule;
use App\Domain\Reprise\ImportGrr;
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

        // US-805-P1 : nuits de répétition et de bascule, sauvegarde complète puis export GRR
        // chiffrés et vérifiés, avant toute migration. Un échec arrête tout (première erreur).
        $avecReprise = $request->boolean('reprise') && config('chamrousse.grr_reprise') === 'ouverte';
        $archives = null;
        if ($avecReprise) {
            @set_time_limit(300);
            try {
                $export = app(ExportBascule::class);
                $sauvegarde = $export->sauvegarder();
                $grr = $export->exporterGrr();
                $archives = ['sauvegarde' => count($sauvegarde['tables']), 'export_grr' => count($grr['tables'])];
            } catch (\Throwable $e) {
                report($e);

                return response()->json(['migre' => false, 'etape' => 'sauvegarde', 'sortie' => 'voir les journaux'], 500);
            }
        }

        $code = Artisan::call('migrate', ['--force' => true]);
        if ($code !== 0) {
            return response()->json(['migre' => false, 'etape' => 'migrations', 'sortie' => 'voir les journaux'], 500);
        }

        // Nuits de répétition et de bascule : première reprise de GRR (aucun admin n'existe encore
        // pour la lancer depuis l'écran). Le script de déploiement s'arrête si le contrôle n'est pas validé (US-805-N1).
        $reprise = null;
        if ($avecReprise) {
            $import = app(ImportGrr::class)->importer();
            $controle = app(ControleReprise::class)->rapport();
            $reprise = [
                'valide' => $controle['valide'],
                'comptes' => $import['comptes_repris'] ?? 0,
                'reservations' => $import['reservations'] ?? 0,
                'propositions' => $import['propositions'] ?? 0,
                'rejets' => is_array($import['rejets'] ?? null) ? count($import['rejets']) : 0,
                'archives' => $archives,
            ];
        }

        return response()->json(['migre' => true, 'sortie' => null, 'reprise' => $reprise]);
    }
}

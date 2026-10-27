<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Reprise\ControleReprise;
use App\Domain\Reprise\ImportGrr;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pas de SSH sur l'offre Starter : l'import de GRR et son contrôle (US-802, US-803)
 * passent par cette route, sur le modèle de la migration : fichier verrou déposé
 * par le script de bascule, jeton à usage unique dont seule l'empreinte est sur le serveur.
 *
 * Dépôt public : la réponse finit dans les journaux de GitHub Actions. Elle ne
 * contient donc que des totaux, jamais un nom, un login ni une adresse.
 */
final class RepriseDeploiementController
{
    public const VERROU = 'deploiement/reprise.verrou';

    public function __invoke(Request $request, ImportGrr $import, ControleReprise $controle): JsonResponse
    {
        $verrou = storage_path('app/'.self::VERROU);
        abort_unless(is_file($verrou), 404);
        $attendu = trim((string) file_get_contents($verrou));
        if ($attendu === '' || ! hash_equals($attendu, hash('sha256', (string) $request->bearerToken()))) {
            abort(403);
        }
        unlink($verrou);

        $r = $import->importer();
        $c = $controle->rapport();
        $nombre = fn (string $cle): int => is_array($r[$cle] ?? null) ? count($r[$cle]) : (int) ($r[$cle] ?? 0);

        return response()->json([
            'valide' => $c['valide'],
            'comptes' => ['grr' => $c['comptes']['grr'], 'repris' => $c['comptes']['repris'], 'a_reinitialiser' => $nombre('a_reinitialiser'), 'anomalies' => $c['comptes']['anomalies']],
            'reservations' => $nombre('reservations'),
            'attributions_a_valider' => $nombre('propositions'),
            'rejets' => $nombre('rejets'),
            'chevauchements' => $nombre('chevauchements'),
            'annees_en_ecart' => array_keys(array_filter($c['annees'], fn ($a) => $a['ecart'])),
        ], $c['valide'] ? 200 : 409);
    }
}

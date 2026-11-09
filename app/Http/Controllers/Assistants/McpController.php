<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistants;

use App\Domain\Assistants\OutilNonAutorise;
use App\Domain\Assistants\ServeurMcp;
use App\Domain\Assistants\TropDAppels;
use App\Models\AccesAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Point d'entrée MCP (épique 10). Le jeton est vérifié par AuthentifierAssistant. */
final class McpController
{
    public function __invoke(Request $request, ServeurMcp $serveur): Response
    {
        if (! $request->isMethod('POST')) {
            // Pas de flux SSE ni de session à fermer : seul POST est servi.
            return response('', 405)->header('Allow', 'POST');
        }
        /** @var AccesAssistant $acces */
        $acces = $request->attributes->get(AccesAssistant::class);

        try {
            $message = json_decode($request->getContent(), true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->json(ServeurMcp::erreur(null, -32700, 'Corps JSON illisible.'), 400);
        }
        if (! is_array($message) || ($message !== [] && array_is_list($message))) {
            return $this->json(ServeurMcp::erreur(null, -32600, 'Un seul message JSON-RPC par requête.'), 400);
        }

        try {
            $reponse = $serveur->traiter($message, $acces);
        } catch (OutilNonAutorise) {
            $id = is_int($message['id'] ?? null) || is_string($message['id'] ?? null) ? $message['id'] : null;

            return $this->json(ServeurMcp::erreur($id, -32001, 'Cet outil demande le droit « Réserver, modifier et annuler en mon nom ».'), 403)
                ->header('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="lecture reservation", resource_metadata="'
                    .url('/.well-known/oauth-protected-resource/mcp').'"');
        } catch (TropDAppels $e) {
            return response()->json(['error' => 'too_many_requests', 'error_description' => $e->getMessage()], 429)
                ->header('Retry-After', (string) $e->secondes);
        }

        return $reponse === null ? response('', 202) : $this->json($reponse);
    }

    /** @param array<string, mixed> $corps */
    private function json(array $corps, int $statut = 200): JsonResponse
    {
        return response()->json($corps, $statut, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)->header('Cache-Control', 'no-store');
    }
}

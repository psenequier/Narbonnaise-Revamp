<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistants;

use App\Domain\Assistants\Autorisations;
use Illuminate\Http\JsonResponse;

/** Métadonnées publiques qui permettent à un assistant de se connecter seul (US-1001-P1). */
final class DecouverteController
{
    /** RFC 9728 : ressource protégée /mcp et son serveur d'autorisation (le site lui-même). */
    public function ressource(): JsonResponse
    {
        return response()->json([
            'resource' => url('/mcp'),
            'authorization_servers' => [url('/')],
            'scopes_supported' => Autorisations::DROITS,
            'bearer_methods_supported' => ['header'],
            'resource_name' => 'Appartement de Chamrousse',
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** RFC 8414 : serveur d'autorisation. */
    public function serveur(): JsonResponse
    {
        return response()->json([
            'issuer' => url('/'),
            'authorization_endpoint' => url('/oauth/autoriser'),
            'token_endpoint' => url('/oauth/jeton'),
            'registration_endpoint' => url('/oauth/enregistrer'),
            'scopes_supported' => Autorisations::DROITS,
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported' => ['S256'],
            'authorization_response_iss_parameter_supported' => true,
        ], 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

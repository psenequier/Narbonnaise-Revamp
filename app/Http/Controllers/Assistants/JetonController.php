<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistants;

use App\Domain\Assistants\Autorisations;
use App\Domain\Assistants\ErreurOAuth;
use App\Models\ClientAssistant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** RFC 6749 § 3.2 : échange du code, puis renouvellement des jetons. */
final class JetonController
{
    public function __invoke(Request $request, Autorisations $autorisations): JsonResponse
    {
        try {
            $client = $this->client($request, $autorisations);
            $resultat = match ($request->input('grant_type')) {
                'authorization_code' => $autorisations->echangerCode(
                    self::texte($request->input('code')), self::texte($request->input('redirect_uri')),
                    self::texte($request->input('code_verifier')), $client,
                ),
                'refresh_token' => $autorisations->renouveler(self::texte($request->input('refresh_token')), $client),
                default => throw new ErreurOAuth('unsupported_grant_type', 'Type de demande non pris en charge.'),
            };
        } catch (ErreurOAuth $e) {
            return $e->reponse();
        }

        return response()->json($resultat)->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
    }

    /** Client public (PKCE seul) ou confidentiel (secret en POST ou en Basic). */
    private function client(Request $request, Autorisations $autorisations): ClientAssistant
    {
        $identifiant = self::texte($request->input('client_id'));
        $secret = self::texte($request->input('client_secret'));
        $basic = (string) $request->header('Authorization', '');
        if (str_starts_with($basic, 'Basic ')) {
            $decode = base64_decode(substr($basic, 6), true);
            if (is_string($decode) && str_contains($decode, ':')) {
                [$i, $s] = explode(':', $decode, 2);
                $identifiant = urldecode($i);
                $secret = urldecode($s);
            }
        }
        $client = $autorisations->client($identifiant);
        if ($client === null
            || ($client->secret_empreinte !== null && ! hash_equals($client->secret_empreinte, Autorisations::empreinte($secret)))) {
            throw new ErreurOAuth('invalid_client', 'Client inconnu ou secret incorrect.', 401);
        }

        return $client;
    }

    private static function texte(mixed $valeur): string
    {
        return is_string($valeur) ? $valeur : '';
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistants;

use App\Domain\Assistants\Autorisations;
use App\Domain\Assistants\ErreurOAuth;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** RFC 7591 : enregistrement dynamique d'un assistant (limité à 10 par heure et par adresse IP). */
final class EnregistrementController
{
    public function __invoke(Request $request, Autorisations $autorisations): JsonResponse
    {
        $metadonnees = json_decode($request->getContent(), true, 16);
        if (! is_array($metadonnees)) {
            return (new ErreurOAuth('invalid_client_metadata', 'Corps JSON attendu.'))->reponse();
        }

        try {
            return response()->json($autorisations->enregistrerClient($metadonnees), 201, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                ->header('Cache-Control', 'no-store');
        } catch (ErreurOAuth $e) {
            return $e->reponse();
        }
    }
}

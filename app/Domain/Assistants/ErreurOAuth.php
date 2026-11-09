<?php

declare(strict_types=1);

namespace App\Domain\Assistants;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Erreur au format OAuth 2.1 (RFC 6749 § 5.2, RFC 7591 § 3.2.2). */
final class ErreurOAuth extends RuntimeException
{
    public function __construct(public readonly string $erreur, string $description, public readonly int $statut = 400)
    {
        parent::__construct($description);
    }

    public function reponse(): JsonResponse
    {
        return response()->json(['error' => $this->erreur, 'error_description' => $this->getMessage()], $this->statut)
            ->header('Cache-Control', 'no-store');
    }
}

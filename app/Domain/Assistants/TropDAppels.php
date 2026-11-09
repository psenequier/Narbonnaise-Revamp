<?php

declare(strict_types=1);

namespace App\Domain\Assistants;

use RuntimeException;

/** Limite d'appels ou d'actions par minute dépassée : réponse 429 sans données. */
final class TropDAppels extends RuntimeException
{
    public function __construct(public readonly int $secondes)
    {
        parent::__construct('Trop de demandes : réessayez dans une minute.');
    }
}

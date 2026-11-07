<?php

declare(strict_types=1);

namespace App\Domain\Assistants;

use RuntimeException;

/** Outil inconnu ou hors des droits accordés : réponse 403 « insufficient_scope » (US-1004-N2). */
final class OutilNonAutorise extends RuntimeException
{
    public function __construct(public readonly string $outil)
    {
        parent::__construct('Cet outil n\'est pas autorisé pour cet accès.');
    }
}

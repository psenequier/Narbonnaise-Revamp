<?php

declare(strict_types=1);

namespace App\Domain\Calendrier;

use DateTimeImmutable;

/**
 * Valeur immuable : une date et un type. Ne dépend pas de la base.
 */
final readonly class Creneau
{
    public function __construct(
        public DateTimeImmutable $date,
        public TypeCreneau $type,
    ) {}

    public function cle(): string
    {
        return $this->date->format('Y-m-d').'-'.$this->type->value;
    }
}

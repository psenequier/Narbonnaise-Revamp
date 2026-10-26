<?php

declare(strict_types=1);

namespace App\Domain\Equite;

final readonly class Verdict
{
    private function __construct(
        public bool $accepte,
        public ?string $motif,
    ) {}

    public static function accord(): self
    {
        return new self(true, null);
    }

    public static function refus(string $motif): self
    {
        return new self(false, $motif);
    }
}

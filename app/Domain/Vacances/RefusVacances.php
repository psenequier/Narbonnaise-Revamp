<?php

declare(strict_types=1);

namespace App\Domain\Vacances;

use RuntimeException;

/** Refus métier sur les vacances (attribution, correction, arbitrage) : message montré tel quel. */
final class RefusVacances extends RuntimeException {}

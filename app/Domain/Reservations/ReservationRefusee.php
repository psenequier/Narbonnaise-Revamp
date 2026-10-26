<?php

declare(strict_types=1);

namespace App\Domain\Reservations;

use RuntimeException;

/** Refus métier : le message est montré tel quel au membre. */
final class ReservationRefusee extends RuntimeException {}

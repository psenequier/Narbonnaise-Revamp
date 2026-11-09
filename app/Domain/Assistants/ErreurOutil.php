<?php

declare(strict_types=1);

namespace App\Domain\Assistants;

use RuntimeException;

/** Demande d'un outil mal formée ou refusée : le message est rendu à l'assistant tel quel. */
final class ErreurOutil extends RuntimeException {}

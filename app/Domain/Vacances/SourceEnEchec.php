<?php

declare(strict_types=1);

namespace App\Domain\Vacances;

use RuntimeException;

/** La source officielle n'a pas donné de dates exploitables : rien n'est modifié, les admins sont alertés. */
final class SourceEnEchec extends RuntimeException {}

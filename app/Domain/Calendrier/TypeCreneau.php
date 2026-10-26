<?php

declare(strict_types=1);

namespace App\Domain\Calendrier;

/**
 * Deux créneaux par date : le jour (J) et la nuit (N) qui commence ce soir-là.
 */
enum TypeCreneau: string
{
    case Jour = 'J';
    case Nuit = 'N';

    public function libelle(): string
    {
        return match ($this) {
            self::Jour => 'jour',
            self::Nuit => 'nuit',
        };
    }
}

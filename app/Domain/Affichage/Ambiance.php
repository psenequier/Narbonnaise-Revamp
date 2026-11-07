<?php

namespace App\Domain\Affichage;

use DateTimeInterface;
use DateTimeZone;

/**
 * Ambiance de l'illustration des pages d'accueil (connexion, mot de passe…) :
 * hiver du 1er octobre au 30 avril, été du 1er mai au 30 septembre (décision de Pascal
 * du 7 nov. 2026). Le jour compte à l'heure de Paris : le 1er octobre à 0 h 30 à Paris,
 * encore le 30 septembre en UTC, est déjà l'hiver.
 */
enum Ambiance: string
{
    case Ete = 'ete';
    case Hiver = 'hiver';

    public static function pour(DateTimeInterface $quand): self
    {
        $mois = (int) \DateTimeImmutable::createFromInterface($quand)
            ->setTimezone(new DateTimeZone('Europe/Paris'))
            ->format('n');

        return ($mois >= 10 || $mois <= 4) ? self::Hiver : self::Ete;
    }

    public function illustration(): string
    {
        return 'images/connexion-'.$this->value.'.svg';
    }

    public function description(): string
    {
        return match ($this) {
            self::Ete => 'Deux cabines de téléphérique, une rouge et une jaune, se croisent au-dessus des alpages',
            self::Hiver => 'Deux cabines de téléphérique, une rouge et une jaune, se croisent au-dessus des montagnes enneigées',
        };
    }
}

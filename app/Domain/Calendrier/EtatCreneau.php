<?php

declare(strict_types=1);

namespace App\Domain\Calendrier;

/**
 * État affiché d'un créneau. Chaque état a son libellé ET son motif : la
 * couleur seule ne distingue jamais deux états (US-201-N3, accessibilité).
 */
enum EtatCreneau: string
{
    case Libre = 'libre';
    case Ouverte = 'ouverte';
    case Exclusive = 'exclusive';
    case Attribue = 'attribue';
    case Attente = 'attente';
    case Ferme = 'ferme';
    case PasOuvert = 'pas-ouvert';

    public function libelle(): string
    {
        return match ($this) {
            self::Libre => 'Libre',
            self::Ouverte => 'Ouvert',
            self::Exclusive => 'Exclusif',
            self::Attribue => 'Attribué',
            self::Attente => 'En attente d\'attribution',
            self::Ferme => 'Fermé',
            self::PasOuvert => 'Pas encore ouvert',
        };
    }

    /** Motif CSS (hachures, points…) qui double la couleur. */
    public function motif(): string
    {
        return match ($this) {
            self::Libre => 'motif-uni',
            self::Ouverte => 'motif-points',
            self::Exclusive => 'motif-plein',
            self::Attribue => 'motif-rayures',
            self::Attente => 'motif-tirets',
            self::Ferme => 'motif-croix',
            self::PasOuvert => 'motif-vide',
        };
    }

    /** On peut y poser une réservation, sous réserve des autres règles. */
    public function reservable(): bool
    {
        return $this === self::Libre || $this === self::Ouverte;
    }
}

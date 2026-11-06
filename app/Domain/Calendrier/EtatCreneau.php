<?php

declare(strict_types=1);

namespace App\Domain\Calendrier;

/**
 * État affiché d'un créneau. Chaque état a son libellé, toujours écrit dans la case :
 * la couleur seule ne distingue jamais deux états (US-201-N3). Les trames ont été retirées (5 nov. 2026).
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

    /** On peut y poser une réservation, sous réserve des autres règles. */
    public function reservable(): bool
    {
        return $this === self::Libre; // un créneau déjà réservé, même ouvert, ne se réserve plus
    }
}

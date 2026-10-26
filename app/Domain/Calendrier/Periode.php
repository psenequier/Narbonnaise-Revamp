<?php

declare(strict_types=1);

namespace App\Domain\Calendrier;

use DateTimeImmutable;

/**
 * Période de vacances bloquée : de la nuit du vendredi qui précède les
 * vacances (inclus) au jour du dimanche qui précède la reprise (inclus).
 */
final readonly class Periode
{
    public function __construct(
        public string $nom,
        public DateTimeImmutable $premiereNuit,
        public DateTimeImmutable $dernierJour,
    ) {}

    public function nombreDeNuits(): int
    {
        // Nuits du vendredi jusqu'à la veille du dernier jour.
        return (int) $this->premiereNuit->diff($this->dernierJour)->days;
    }

    public function nombreDeJours(): int
    {
        // Jours du samedi jusqu'au dimanche final.
        return (int) $this->premiereNuit->diff($this->dernierJour)->days;
    }

    /**
     * Tous les créneaux de la période, dans l'ordre chronologique.
     *
     * @return list<Creneau>
     */
    public function creneaux(): array
    {
        $creneaux = [];
        $date = $this->premiereNuit;

        while ($date < $this->dernierJour) {
            $creneaux[] = new Creneau($date, TypeCreneau::Nuit);
            $date = $date->modify('+1 day');
            $creneaux[] = new Creneau($date, TypeCreneau::Jour);
        }

        return $creneaux;
    }

    public function contient(Creneau $creneau): bool
    {
        if ($creneau->type === TypeCreneau::Nuit) {
            return $creneau->date >= $this->premiereNuit && $creneau->date < $this->dernierJour;
        }

        return $creneau->date > $this->premiereNuit && $creneau->date <= $this->dernierJour;
    }
}

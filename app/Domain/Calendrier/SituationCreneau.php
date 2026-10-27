<?php

declare(strict_types=1);

namespace App\Domain\Calendrier;

use App\Models\Reservation;

/** Ce que l'on sait d'un créneau pour l'afficher : état, lignée, réservations actives. */
final readonly class SituationCreneau
{
    /** @param list<Reservation> $reservations */
    public function __construct(
        public Creneau $creneau,
        public EtatCreneau $etat,
        public ?string $lignee = null,
        public ?string $couleurLignee = null,
        public array $reservations = [],
    ) {}

    public function cle(): string
    {
        return $this->creneau->cle();
    }

    public function contientMembre(int $userId): bool
    {
        foreach ($this->reservations as $r) {
            if ($r->user_id === $userId) {
                return true;
            }
        }

        return false;
    }
}

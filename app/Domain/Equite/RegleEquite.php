<?php

declare(strict_types=1);

namespace App\Domain\Equite;

use DateTimeImmutable;

/**
 * « Gestion des réservations multiples et de l'équité », hors vacances.
 *
 * - délai maximal : on ne réserve pas plus de N jours à l'avance (0 = pas de limite) ;
 * - au-delà de X réservations sur 12 mois glissants, le membre ne réserve
 *   qu'au plus Y jours à l'avance (X ou Y à 0 = règle désactivée).
 *
 * « Au-delà de X » : le membre qui a déjà X réservations actives ou passées
 * sur les 12 derniers mois est soumis au délai réduit pour la suivante.
 */
final readonly class RegleEquite
{
    public function __construct(
        public int $delaiMaximalJours = 0,
        public int $seuilReservations = 0,
        public int $delaiReduitJours = 0,
    ) {}

    public function verifier(
        DateTimeImmutable $aujourdhui,
        DateTimeImmutable $premierCreneau,
        int $reservationsSur12Mois,
    ): Verdict {
        // Comparaison de jours civils : on ignore les fuseaux (Paris pour « aujourd'hui »,
        // UTC pour une date lue en base), sinon minuit à Paris tombe la veille en UTC.
        $utc = new \DateTimeZone('UTC');
        $du = new DateTimeImmutable($aujourdhui->format('Y-m-d'), $utc);
        $au = new DateTimeImmutable($premierCreneau->format('Y-m-d'), $utc);
        $jours = (int) $du->diff($au)->format('%r%a');

        if ($jours < 0) {
            return Verdict::refus('Ce créneau est déjà passé.');
        }

        if ($this->delaiMaximalJours > 0 && $jours > $this->delaiMaximalJours) {
            return Verdict::refus("On ne peut pas réserver plus de {$this->delaiMaximalJours} jours à l'avance.");
        }

        if ($this->regleMultipleActive()
            && $reservationsSur12Mois >= $this->seuilReservations
            && $jours > $this->delaiReduitJours) {
            $possibleLe = $premierCreneau->modify("-{$this->delaiReduitJours} days")->format('d/m/Y');

            return Verdict::refus(
                "Vous avez déjà {$reservationsSur12Mois} réservations sur les 12 derniers mois : "
                ."vous pouvez réserver au plus {$this->delaiReduitJours} jours à l'avance, "
                ."donc à partir du {$possibleLe} pour cette date."
            );
        }

        return Verdict::accord();
    }

    public function regleMultipleActive(): bool
    {
        return $this->seuilReservations > 0 && $this->delaiReduitJours > 0;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Reprise;

use App\Domain\Calendrier\Creneau;
use App\Domain\Calendrier\TypeCreneau;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Traduction d'une réservation GRR (heures de début et de fin) en créneaux (ADR 014).
 * Un créneau est occupé dès que la réservation recouvre une partie de sa plage :
 * Journée de 10 h à 17 h, Nuit de 20 h au lendemain 8 h (heure de Paris).
 * Exemple : samedi 14 h → dimanche 18 h = samedi J, samedi N, dimanche J.
 */
final class CreneauxGrr
{
    private const JOUR = [10, 17];

    private const NUIT = [20, 32]; // 32 h = lendemain 8 h

    /** @return list<Creneau> */
    public static function depuis(int $debut, int $fin): array
    {
        if ($debut <= 0 || $fin <= $debut) {
            return [];
        }
        $paris = new DateTimeZone('Europe/Paris');
        $d = (new DateTimeImmutable('@'.$debut))->setTimezone($paris)->setTime(0, 0)->modify('-1 day');
        $dernier = (new DateTimeImmutable('@'.$fin))->setTimezone($paris)->setTime(0, 0);

        $creneaux = [];
        for (; $d <= $dernier; $d = $d->modify('+1 day')) {
            foreach ([[TypeCreneau::Jour, self::JOUR], [TypeCreneau::Nuit, self::NUIT]] as [$type, [$de, $a]]) {
                $plageDebut = $d->modify("+{$de} hours")->getTimestamp();
                $plageFin = $d->modify("+{$a} hours")->getTimestamp();
                if ($debut < $plageFin && $fin > $plageDebut) {
                    $creneaux[] = new Creneau($d, $type);
                }
            }
        }

        return $creneaux;
    }
}

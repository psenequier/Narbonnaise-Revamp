<?php

declare(strict_types=1);

namespace App\Domain\Reprise;

use App\Domain\Calendrier\Creneau;
use App\Domain\Calendrier\TypeCreneau;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Traduction d'une réservation GRR en créneaux (ADR 014), selon le mode du domaine GRR :
 *
 * - mode « périodes » (celui de l'appartement : Journée = période 0, Nuit = période 1) :
 *   GRR enregistre chaque période à midi + son numéro en minutes ; le créneau est
 *   repris si son instant est dans [début, fin[. Exact, sans interprétation.
 * - mode « heures » : un créneau est occupé dès que la réservation recouvre une partie
 *   de sa plage, Journée de 10 h à 17 h, Nuit de 20 h au lendemain 8 h
 *   (samedi 14 h → dimanche 18 h = samedi J, samedi N, dimanche J).
 */
final class CreneauxGrr
{
    private const JOUR = [10, 17];

    private const NUIT = [20, 32]; // 32 h = lendemain 8 h

    /** @return list<Creneau> */
    public static function depuis(int $debut, int $fin, bool $periodes = false): array
    {
        if ($debut <= 0 || $fin <= $debut) {
            return [];
        }

        return $periodes ? self::parPeriodes($debut, $fin) : self::parHeures($debut, $fin);
    }

    /** @return list<Creneau> */
    private static function parPeriodes(int $debut, int $fin): array
    {
        $creneaux = [];
        $d = self::jour($debut);
        for ($dernier = self::jour($fin); $d <= $dernier; $d = $d->modify('+1 day')) {
            foreach ([[TypeCreneau::Jour, 0], [TypeCreneau::Nuit, 1]] as [$type, $numero]) {
                $instant = $d->setTime(12, $numero)->getTimestamp();
                if ($instant >= $debut && $instant < $fin) {
                    $creneaux[] = new Creneau($d, $type);
                }
            }
        }

        return $creneaux;
    }

    /** @return list<Creneau> */
    private static function parHeures(int $debut, int $fin): array
    {
        $creneaux = [];
        $d = self::jour($debut)->modify('-1 day');
        for ($dernier = self::jour($fin); $d <= $dernier; $d = $d->modify('+1 day')) {
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

    private static function jour(int $instant): DateTimeImmutable
    {
        return (new DateTimeImmutable('@'.$instant))->setTimezone(new DateTimeZone('Europe/Paris'))->setTime(0, 0);
    }
}

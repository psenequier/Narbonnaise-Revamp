<?php

declare(strict_types=1);

namespace App\Domain\Calendrier;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Transforme les dates officielles des vacances (zone A) en période bloquée.
 *
 * Le jeu de données du ministère publie le début des vacances et le jour de
 * reprise en UTC (souvent la veille à 23 h). On les ramène d'abord à Paris.
 */
final class CalculPeriodes
{
    private DateTimeZone $paris;

    public function __construct()
    {
        $this->paris = new DateTimeZone('Europe/Paris');
    }

    /**
     * @param  string  $debutVacances  premier jour sans classe (ISO 8601, toute zone)
     * @param  string  $reprise  jour de reprise des cours (ISO 8601, toute zone)
     */
    public function periode(string $nom, string $debutVacances, string $reprise): Periode
    {
        $debut = $this->jourAParis($debutVacances);
        $retour = $this->jourAParis($reprise);

        if ($retour <= $debut) {
            throw new InvalidArgumentException("Reprise antérieure au début des vacances pour « {$nom} ».");
        }

        $vendredi = $debut->format('N') === '5' ? $debut : $debut->modify('last friday');
        $dimanche = $retour->format('N') === '7' ? $retour : $retour->modify('last sunday');

        return new Periode($nom, $vendredi, $dimanche);
    }

    private function jourAParis(string $iso): DateTimeImmutable
    {
        // Une date sans heure est déjà un jour civil : pas de conversion.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $iso) === 1) {
            return new DateTimeImmutable($iso, $this->paris);
        }

        return (new DateTimeImmutable($iso))->setTimezone($this->paris)->setTime(0, 0);
    }
}

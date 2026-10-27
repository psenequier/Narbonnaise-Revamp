<?php

declare(strict_types=1);

namespace App\Domain\Emails;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Événement iCalendar (RFC 5545) d'une réservation, en journées entières.
 * Même UID à chaque envoi ; la séquence augmente à chaque modification.
 */
final class Ics
{
    /**
     * @param  string  $debut  premier jour (Y-m-d)
     * @param  string  $finExclusive  lendemain du dernier jour occupé (Y-m-d)
     */
    public static function evenement(
        string $uid,
        int $sequence,
        string $debut,
        string $finExclusive,
        string $titre,
        string $description,
        bool $annule = false,
        ?DateTimeImmutable $horodatage = null,
    ): string {
        $horodatage ??= new DateTimeImmutable('now');
        $utc = $horodatage->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');

        $lignes = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Appartement Chamrousse//Reservations//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.self::texte($uid),
            'SEQUENCE:'.$sequence,
            'DTSTAMP:'.$utc,
            'DTSTART;VALUE=DATE:'.str_replace('-', '', $debut),
            'DTEND;VALUE=DATE:'.str_replace('-', '', $finExclusive),
            'SUMMARY:'.self::texte($titre),
            'DESCRIPTION:'.self::texte($description),
            'STATUS:'.($annule ? 'CANCELLED' : 'CONFIRMED'),
            'TRANSP:TRANSPARENT',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", array_map(self::plier(...), $lignes))."\r\n";
    }

    /** Échappement des valeurs texte (RFC 5545, 3.3.11). */
    private static function texte(string $valeur): string
    {
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ['\\\\', '\;', '\\,', '\\n', '\\n', ''], $valeur);
    }

    /** Lignes de 75 octets au plus, continuées par un espace, sans couper un caractère UTF-8. */
    private static function plier(string $ligne): string
    {
        $morceaux = [];
        $courant = '';
        foreach (mb_str_split($ligne) as $caractere) {
            $limite = $morceaux === [] ? 75 : 74;
            if (strlen($courant.$caractere) > $limite) {
                $morceaux[] = $courant;
                $courant = '';
            }
            $courant .= $caractere;
        }
        $morceaux[] = $courant;

        return implode("\r\n ", $morceaux);
    }
}

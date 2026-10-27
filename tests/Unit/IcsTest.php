<?php

use App\Domain\Emails\Ics;

it('produit un événement iCalendar valide, en journées entières', function () {
    $ics = Ics::evenement('reservation-7@exemple.test', 2, '2026-11-14', '2026-11-16', 'Séjour', "Ligne 1\nVirgule, point-virgule; antislash \\", horodatage: new DateTimeImmutable('2026-11-02 10:00', new DateTimeZone('Europe/Paris')));

    expect($ics)->toStartWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n")
        ->toEndWith("END:VCALENDAR\r\n")
        ->toContain("UID:reservation-7@exemple.test\r\n")
        ->toContain("SEQUENCE:2\r\n")
        ->toContain("DTSTAMP:20261102T090000Z\r\n")
        ->toContain("DTSTART;VALUE=DATE:20261114\r\n")
        ->toContain("DTEND;VALUE=DATE:20261116\r\n")
        ->toContain('DESCRIPTION:Ligne 1\nVirgule\, point-virgule\; antislash \\\\')
        ->and(substr_count($ics, 'BEGIN:VEVENT'))->toBe(1);

    foreach (explode("\r\n", rtrim($ics)) as $ligne) {
        expect(strlen($ligne))->toBeLessThanOrEqual(75);
    }
});

it('plie les lignes longues sans couper un caractère accentué', function () {
    $ics = Ics::evenement('u', 0, '2026-11-14', '2026-11-15', str_repeat('é', 80), 'd');

    $deplie = str_replace("\r\n ", '', $ics);
    expect($deplie)->toContain('SUMMARY:'.str_repeat('é', 80))
        ->and(mb_check_encoding($ics, 'UTF-8'))->toBeTrue();
});

it('marque un événement annulé', function () {
    expect(Ics::evenement('u', 1, '2026-11-14', '2026-11-15', 't', 'd', annule: true))->toContain("STATUS:CANCELLED\r\n");
});

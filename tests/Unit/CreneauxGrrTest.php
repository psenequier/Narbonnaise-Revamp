<?php

use App\Domain\Reprise\CreneauxGrr;

function cles(string $debut, string $fin): array
{
    $paris = new DateTimeZone('Europe/Paris');

    return array_map(fn ($c) => $c->cle(), CreneauxGrr::depuis(
        (new DateTimeImmutable($debut, $paris))->getTimestamp(),
        (new DateTimeImmutable($fin, $paris))->getTimestamp(),
    ));
}

it('US-802-P1 transforme samedi 14 h → dimanche 18 h en samedi J et N, dimanche J', function () {
    expect(cles('2025-11-15 14:00', '2025-11-16 18:00'))->toBe(['2025-11-15-J', '2025-11-15-N', '2025-11-16-J']);
});

it('traduit les cas usuels de GRR', function (string $debut, string $fin, array $attendu) {
    expect(cles($debut, $fin))->toBe($attendu);
})->with([
    'journée seule' => ['2025-01-11 09:00', '2025-01-11 18:00', ['2025-01-11-J']],
    'nuit seule' => ['2025-01-11 19:00', '2025-01-12 09:00', ['2025-01-11-N']],
    'semaine du samedi au samedi' => ['2025-02-15 12:00', '2025-02-22 12:00', [
        '2025-02-15-J', '2025-02-15-N', '2025-02-16-J', '2025-02-16-N', '2025-02-17-J', '2025-02-17-N', '2025-02-18-J', '2025-02-18-N',
        '2025-02-19-J', '2025-02-19-N', '2025-02-20-J', '2025-02-20-N', '2025-02-21-J', '2025-02-21-N', '2025-02-22-J']],
    'arrivée tard le vendredi' => ['2025-03-07 22:00', '2025-03-09 16:00', ['2025-03-07-N', '2025-03-08-J', '2025-03-08-N', '2025-03-09-J']],
    'une heure en soirée : aucun créneau' => ['2025-02-01 18:00', '2025-02-01 19:00', []],
]);

it('ne donne aucun créneau pour des dates invalides', function () {
    expect(CreneauxGrr::depuis(0, 1000))->toBe([])
        ->and(CreneauxGrr::depuis(2000, 1000))->toBe([]);
});

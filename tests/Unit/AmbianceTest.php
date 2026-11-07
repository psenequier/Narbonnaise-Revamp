<?php

use App\Domain\Affichage\Ambiance;

it('passe en hiver le 1er octobre et revient en été le 1er mai', function (string $quand, Ambiance $attendue) {
    expect(Ambiance::pour(new DateTimeImmutable($quand, new DateTimeZone('Europe/Paris'))))->toBe($attendue);
})->with([
    ['2026-09-30 23:59', Ambiance::Ete],
    ['2026-10-01 00:00', Ambiance::Hiver],
    ['2026-12-25 12:00', Ambiance::Hiver],
    ['2027-04-30 23:59', Ambiance::Hiver],
    ['2027-05-01 00:00', Ambiance::Ete],
    ['2027-08-15 12:00', Ambiance::Ete],
]);

it('compte le jour à l\'heure de Paris, pas en UTC', function () {
    // 30 septembre 22 h 30 UTC = 1er octobre 0 h 30 à Paris : déjà l'hiver.
    expect(Ambiance::pour(new DateTimeImmutable('2026-09-30 22:30', new DateTimeZone('UTC'))))->toBe(Ambiance::Hiver);
});

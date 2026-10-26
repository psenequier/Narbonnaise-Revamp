<?php

use App\Domain\Calendrier\CalculPeriodes;
use App\Domain\Calendrier\Creneau;
use App\Domain\Calendrier\TypeCreneau;

// Valeurs publiées par data.education.gouv.fr pour la zone A, en UTC (veille à 23 h).
dataset('vacances 2026-2027', [
    'Noël' => ['noel', '2026-12-18T23:00:00+00:00', '2027-01-03T23:00:00+00:00', '2026-12-18', '2027-01-03', 16],
    'février' => ['hiver', '2027-02-12T23:00:00+00:00', '2027-02-28T23:00:00+00:00', '2027-02-12', '2027-02-28', 16],
]);

it('US-401-P1 calcule la période du vendredi nuit au dimanche jour', function (string $nom, string $debut, string $reprise, string $premiereNuit, string $dernierJour, int $nuits) {
    $periode = (new CalculPeriodes)->periode($nom, $debut, $reprise);

    expect($periode->premiereNuit->format('Y-m-d'))->toBe($premiereNuit)
        ->and($periode->premiereNuit->format('N'))->toBe('5')
        ->and($periode->dernierJour->format('Y-m-d'))->toBe($dernierJour)
        ->and($periode->dernierJour->format('N'))->toBe('7')
        ->and($periode->nombreDeNuits())->toBe($nuits)
        ->and($periode->creneaux())->toHaveCount($nuits * 2);
})->with('vacances 2026-2027');

it('accepte aussi des dates sans heure', function () {
    $periode = (new CalculPeriodes)->periode('hiver', '2027-02-13', '2027-03-01');

    expect($periode->premiereNuit->format('Y-m-d'))->toBe('2027-02-12')
        ->and($periode->dernierJour->format('Y-m-d'))->toBe('2027-02-28');
});

it('commence par la nuit du vendredi et finit par le jour du dimanche', function () {
    $creneaux = (new CalculPeriodes)->periode('noel', '2026-12-19', '2027-01-04')->creneaux();

    expect($creneaux[0]->cle())->toBe('2026-12-18-N')
        ->and(end($creneaux)->cle())->toBe('2027-01-03-J');
});

it('sait si un créneau appartient à la période', function () {
    $periode = (new CalculPeriodes)->periode('noel', '2026-12-19', '2027-01-04');
    $c = fn (string $d, TypeCreneau $t) => new Creneau(new DateTimeImmutable($d, new DateTimeZone('Europe/Paris')), $t);

    expect($periode->contient($c('2026-12-18', TypeCreneau::Jour)))->toBeFalse()   // vendredi journée : libre
        ->and($periode->contient($c('2026-12-18', TypeCreneau::Nuit)))->toBeTrue()
        ->and($periode->contient($c('2027-01-03', TypeCreneau::Jour)))->toBeTrue()
        ->and($periode->contient($c('2027-01-03', TypeCreneau::Nuit)))->toBeFalse(); // dimanche soir : libre
});

it('refuse une reprise antérieure au début', function () {
    (new CalculPeriodes)->periode('noel', '2027-01-04', '2026-12-19');
})->throws(InvalidArgumentException::class);

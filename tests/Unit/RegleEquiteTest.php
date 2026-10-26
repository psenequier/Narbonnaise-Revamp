<?php

use App\Domain\Equite\RegleEquite;

beforeEach(function () {
    $this->aujourdhui = new DateTimeImmutable('2026-11-02');
});

it('US-311-N1 refuse J+15 au membre qui a 6 réservations quand X = 5 et Y = 14', function () {
    $verdict = (new RegleEquite(0, 5, 14))->verifier($this->aujourdhui, $this->aujourdhui->modify('+15 days'), 6);

    expect($verdict->accepte)->toBeFalse()
        ->and($verdict->motif)->toContain('6 réservations')   // nombre compté
        ->and($verdict->motif)->toContain('03/11/2026');      // date à partir de laquelle c'est possible
});

it('accepte J+14 dans le même cas', function () {
    expect((new RegleEquite(0, 5, 14))->verifier($this->aujourdhui, $this->aujourdhui->modify('+14 days'), 6)->accepte)->toBeTrue();
});

it('ne limite pas un membre sous le seuil', function () {
    expect((new RegleEquite(0, 5, 14))->verifier($this->aujourdhui, $this->aujourdhui->modify('+200 days'), 4)->accepte)->toBeTrue();
});

it('désactive la règle quand X ou Y vaut 0', function (int $x, int $y) {
    $regle = new RegleEquite(0, $x, $y);

    expect($regle->regleMultipleActive())->toBeFalse()
        ->and($regle->verifier($this->aujourdhui, $this->aujourdhui->modify('+300 days'), 99)->accepte)->toBeTrue();
})->with([[0, 14], [5, 0], [0, 0]]);

it('applique le délai maximal de réservation à l\'avance', function () {
    $regle = new RegleEquite(90);

    expect($regle->verifier($this->aujourdhui, $this->aujourdhui->modify('+90 days'), 0)->accepte)->toBeTrue()
        ->and($regle->verifier($this->aujourdhui, $this->aujourdhui->modify('+91 days'), 0)->accepte)->toBeFalse();
});

it('US-301-N3 refuse une date passée', function () {
    expect((new RegleEquite)->verifier($this->aujourdhui, $this->aujourdhui->modify('-1 day'), 0)->accepte)->toBeFalse();
});

<?php

use App\Domain\Parametres;
use Illuminate\Support\Facades\DB;

it('renvoie les valeurs par défaut préremplies (règles d\'équité désactivées)', function () {
    $p = app(Parametres::class);

    expect($p->entier('equite.seuil_reservations'))->toBe(0)
        ->and($p->regleEquite()->regleMultipleActive())->toBeFalse()
        ->and($p->lire('station.altitude'))->toBe('1650');
});

it('lit une valeur enregistrée par un admin, après oubli du cache', function () {
    $p = app(Parametres::class);
    $p->lire('station.altitude'); // met le cache en place

    DB::table('parametres')->insert(['cle' => 'equite.delai_maximal_jours', 'valeur' => '90', 'created_at' => now(), 'updated_at' => now()]);
    $p->oublier();

    expect($p->regleEquite()->delaiMaximalJours)->toBe(90);
});

it('ramène une valeur négative à 0', function () {
    DB::table('parametres')->insert(['cle' => 'equite.delai_reduit_jours', 'valeur' => '-5', 'created_at' => now(), 'updated_at' => now()]);

    expect(app(Parametres::class)->entier('equite.delai_reduit_jours'))->toBe(0);
});

it('refuse un paramètre inconnu', function () {
    app(Parametres::class)->lire('inconnu');
})->throws(InvalidArgumentException::class);

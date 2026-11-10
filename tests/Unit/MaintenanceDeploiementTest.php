<?php

declare(strict_types=1);

require_once __DIR__.'/../../bootstrap/maintenance.php';

it('laisse passer la route de migration pendant la maintenance, avec ou sans reprise', function () {
    $down = (string) tempnam(sys_get_temp_dir(), 'down');
    expect(chamrousse_bloque_par_maintenance($down, '/_deploiement/migrer'))->toBeFalse()
        ->and(chamrousse_bloque_par_maintenance($down, '/_deploiement/migrer?reprise=1'))->toBeFalse();
    unlink($down);
});

it('bloque toute autre page pendant la maintenance', function () {
    $down = (string) tempnam(sys_get_temp_dir(), 'down');
    expect(chamrousse_bloque_par_maintenance($down, '/login'))->toBeTrue()
        ->and(chamrousse_bloque_par_maintenance($down, '/_deploiement/migrer/autre'))->toBeTrue()
        ->and(chamrousse_bloque_par_maintenance($down, '/?x=/_deploiement/migrer'))->toBeTrue();
    unlink($down);
});

it('ne bloque rien hors maintenance', function () {
    $absent = sys_get_temp_dir().'/aucun-fichier-down-'.bin2hex(random_bytes(4));
    expect(chamrousse_bloque_par_maintenance($absent, '/login'))->toBeFalse();
});

<?php

use App\Domain\Calendrier\EtatCreneau;

it('US-201-N3 donne à chaque état un libellé propre, écrit dans la case : jamais la couleur seule', function () {
    $etats = EtatCreneau::cases();
    $libelles = array_map(fn (EtatCreneau $e) => $e->libelle(), $etats);

    expect(array_unique($libelles))->toHaveCount(count($etats));
});

it('ne permet de réserver que sur un créneau libre', function () {
    $reservables = array_values(array_filter(EtatCreneau::cases(), fn (EtatCreneau $e) => $e->reservable()));

    expect($reservables)->toBe([EtatCreneau::Libre]);
});

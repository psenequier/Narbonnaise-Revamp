<?php

use App\Domain\Calendrier\EtatCreneau;

it('US-201-N3 donne à chaque état un libellé et un motif propres, jamais la couleur seule', function () {
    $etats = EtatCreneau::cases();
    $libelles = array_map(fn (EtatCreneau $e) => $e->libelle(), $etats);
    $motifs = array_map(fn (EtatCreneau $e) => $e->motif(), $etats);

    expect(array_unique($libelles))->toHaveCount(count($etats))
        ->and(array_unique($motifs))->toHaveCount(count($etats));
});

it('ne permet de réserver que sur un créneau libre ou ouvert', function () {
    $reservables = array_values(array_filter(EtatCreneau::cases(), fn (EtatCreneau $e) => $e->reservable()));

    expect($reservables)->toBe([EtatCreneau::Libre, EtatCreneau::Ouverte]);
});

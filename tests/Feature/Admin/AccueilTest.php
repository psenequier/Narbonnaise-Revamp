<?php

it('présente les rubriques d\'administration dans une seule grille régulière', function () {
    $this->actingAs(adminAvec2fa());

    $html = $this->get('/admin')->assertOk()
        ->assertSeeInOrder(['Vacances et attributions', 'Lignées', 'Utilisateurs', 'Journal des actions', 'Reprise de GRR'])
        ->assertDontSee('Cumul par lignée')
        ->getContent();

    // Une seule liste : sinon l'écart de la page s'ajoute entre deux rangées.
    expect(substr_count((string) $html, 'data-cartes-admin'))->toBe(1)
        ->and(substr_count((string) $html, '<ul class="grid'))->toBe(1);
});

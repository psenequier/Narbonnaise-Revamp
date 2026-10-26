<?php

/*
 * Premier test navigateur : il prouve que la chaîne Pest Browser fonctionne.
 * Les parcours des user stories (US-301-P4, etc.) viennent avec les écrans.
 */

it('sert la page de santé sans erreur JavaScript', function () {
    $this->seed(\Database\Seeders\DatabaseSeeder::class);

    $page = visit('/sante');

    $page->assertSee('base')
        ->assertNoJavascriptErrors();
})->skip('À activer avec la page de connexion (lot MVP 1)');

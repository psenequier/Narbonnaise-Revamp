<?php

use App\Models\User;

it('affiche la page de connexion avec les en-têtes de sécurité', function () {
    $this->get('/login')
        ->assertOk()
        ->assertSee('Connexion')
        ->assertHeader('Content-Security-Policy')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('redirige un visiteur non connecté vers la connexion', function () {
    $this->get('/')->assertRedirect('/login');
});

it('n\'offre aucune inscription libre', function () {
    $this->get('/register')->assertNotFound();
});

it('applique les en-têtes de sécurité aussi sur une page introuvable', function () {
    $this->get('/page-inexistante')->assertNotFound()->assertHeader('Content-Security-Policy');
});

it('connecte un membre avec le bon mot de passe', function () {
    $membre = User::factory()->create();

    $this->post('/login', ['email' => $membre->email, 'password' => 'Mot-de-passe-de-test-42!'])
        ->assertRedirect('/');
    $this->assertAuthenticatedAs($membre);
});

it('refuse un mauvais mot de passe sans dire si le compte existe', function () {
    $membre = User::factory()->create();

    $this->post('/login', ['email' => $membre->email, 'password' => 'faux'])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => 'inconnu@example.test', 'password' => 'faux'])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('bloque après 5 essais en une minute', function () {
    $membre = User::factory()->create();
    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => $membre->email, 'password' => 'faux']);
    }

    $this->post('/login', ['email' => $membre->email, 'password' => 'Mot-de-passe-de-test-42!'])->assertStatus(429);
    $this->assertGuest();
});

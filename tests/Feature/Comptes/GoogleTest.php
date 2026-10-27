<?php

use App\Models\User;

beforeEach(fn () => config([
    'services.google.client_id' => 'client-test',
    'services.google.client_secret' => 'secret-test',
    'services.google.redirect' => 'http://localhost/auth/google/retour',
]));

it('US-102-P1 connecte un compte actif dont l\'adresse est vérifiée par Google', function () {
    $membre = User::factory()->create(['email' => 'camille@example.test']);
    simulerGoogle('g-1', 'Camille@example.test');

    $this->get('/auth/google/retour')->assertRedirect('/');
    $this->assertAuthenticatedAs($membre);
});

it('US-102-P2 connecte via un compte Google déjà lié, même avec une autre adresse', function () {
    $membre = User::factory()->create(['email' => 'camille@example.test']);
    $membre->forceFill(['google_id' => 'g-2'])->save();
    simulerGoogle('g-2', 'autre-adresse@example.test');

    $this->get('/auth/google/retour')->assertRedirect('/');
    $this->assertAuthenticatedAs($membre);
});

it('US-102-P3 ne demande à Google que openid, email et profile', function () {
    $cible = $this->get('/auth/google/redirection')->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url((string) $cible, PHP_URL_QUERY), $parametres);
    $droits = explode(' ', $parametres['scope']);
    sort($droits);

    expect($cible)->toStartWith('https://accounts.google.com/')
        ->and($droits)->toBe(['email', 'openid', 'profile']);
});

it('US-102-N1 ne crée aucun compte pour une adresse Google inconnue', function () {
    User::factory()->create();
    simulerGoogle('g-3', 'inconnu@example.test');

    $this->get('/auth/google/retour')->assertRedirect('/login')->assertSessionHasErrors('email');

    expect(User::count())->toBe(1)
        ->and(session('errors')->first('email'))->toContain('Contactez un admin');
    $this->assertGuest();
});

it('US-102-N2 refuse une adresse Google non vérifiée', function () {
    User::factory()->create(['email' => 'camille@example.test']);
    simulerGoogle('g-4', 'camille@example.test', verifiee: false);

    $this->get('/auth/google/retour')->assertRedirect('/login')->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('US-102-N3 refuse un retour sans state ou avec un state différent', function () {
    User::factory()->create(['email' => 'camille@example.test']);

    // Vrai pilote Socialite : la vérification du state précède tout appel à Google.
    $this->get('/auth/google/retour?code=abc')->assertRedirect('/login')->assertSessionHasErrors('email');
    $this->withSession(['state' => 'attendu'])->get('/auth/google/retour?code=abc&state=autre')
        ->assertRedirect('/login')->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('US-102-N4 refuse un compte désactivé', function () {
    $membre = User::factory()->create(['email' => 'camille@example.test']);
    $membre->forceFill(['actif' => false])->save();
    simulerGoogle('g-5', 'camille@example.test');

    $this->get('/auth/google/retour')->assertRedirect('/login');
    $this->assertGuest();
});

it('US-107-P1 enregistre l\'identifiant Google lié depuis le profil, puis permet la connexion', function () {
    $membre = User::factory()->create(['email' => 'camille@example.test']);

    $this->actingAs($membre)->get('/profil/google/lier')->assertRedirect();
    simulerGoogle('g-6', 'perso@example.test');
    $this->actingAs($membre)->withSession(['google.liaison' => true])->get('/auth/google/retour')->assertRedirect('/profil');
    expect($membre->fresh()->google_id)->toBe('g-6');

    auth()->logout();
    $this->get('/auth/google/retour')->assertRedirect('/');
    $this->assertAuthenticatedAs($membre);
});

it('US-107-P2 refuse Google après déliaison, le mot de passe fonctionne toujours', function () {
    $membre = User::factory()->create(['email' => 'camille@example.test']);
    $membre->forceFill(['google_id' => 'g-7'])->save();

    $this->actingAs($membre)->delete('/profil/google')->assertRedirect('/profil');
    expect($membre->fresh()->google_id)->toBeNull();
    auth()->logout();

    simulerGoogle('g-7', 'perso@example.test');
    $this->get('/auth/google/retour')->assertRedirect('/login');
    $this->assertGuest();
    $this->post('/login', ['email' => $membre->email, 'password' => MDP_TEST])->assertRedirect('/');
});

it('US-107-N1 refuse un compte Google déjà lié à un autre membre', function () {
    $autre = User::factory()->create();
    $autre->forceFill(['google_id' => 'g-8'])->save();
    $membre = User::factory()->create();
    simulerGoogle('g-8', 'perso@example.test');

    $this->actingAs($membre)->withSession(['google.liaison' => true])->get('/auth/google/retour')
        ->assertRedirect('/profil')->assertSessionHasErrors('google');

    expect($membre->fresh()->google_id)->toBeNull()
        ->and($autre->fresh()->google_id)->toBe('g-8');
});

it('US-107-N2 redirige une liaison sans connexion vers la page de connexion', function () {
    $this->get('/profil/google/lier')->assertRedirect('/login');
});

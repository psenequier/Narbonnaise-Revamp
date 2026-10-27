<?php

use App\Models\User;

function connecter(User $user): \Illuminate\Testing\TestResponse
{
    return test()->post('/login', ['email' => $user->email, 'password' => MDP_TEST]);
}

it('US-108-P1 donne accès à l\'espace admin avec un code TOTP valide', function () {
    $admin = User::factory()->admin()->create();
    $secret = activerDoubleAuthentification($admin);

    connecter($admin)->assertRedirect('/two-factor-challenge');
    $this->assertGuest();
    $this->post('/two-factor-challenge', ['code' => codeTotp($secret)])->assertRedirect();

    $this->assertAuthenticatedAs($admin);
    $this->get('/admin')->assertOk()->assertSee('Administration');
});

it('US-108-P2 accepte un code de secours valide puis l\'invalide', function () {
    $admin = User::factory()->admin()->create();
    activerDoubleAuthentification($admin, ['SECOURS-0001', 'SECOURS-0002']);

    connecter($admin);
    $this->post('/two-factor-challenge', ['recovery_code' => 'SECOURS-0001'])->assertRedirect();

    $this->assertAuthenticatedAs($admin);
    expect($admin->fresh()->recoveryCodes())->not->toContain('SECOURS-0001')->toContain('SECOURS-0002');
});

it('US-108-P3 demande le code à chaque connexion d\'un membre qui a activé la double authentification', function () {
    $membre = User::factory()->create();
    $secret = activerDoubleAuthentification($membre);

    foreach (range(1, 2) as $i) {
        connecter($membre)->assertRedirect('/two-factor-challenge');
        $this->assertGuest();
        $this->travel(1)->minutes(); // un code TOTP ne sert qu'une fois
        $this->post('/two-factor-challenge', ['code' => codeTotp($secret)])->assertRedirect('/');
        auth()->logout();
    }
});

it('US-108-N1 renvoie un admin sans double authentification vers l\'activation et refuse l\'admin (403)', function () {
    $admin = User::factory()->admin()->create();

    connecter($admin)->assertRedirect('/profil');
    $this->get('/profil')->assertOk()->assertSee('activer la double authentification');
    $this->get('/admin')->assertForbidden();
});

it('US-108-N2 refuse un code faux ou expiré et bloque 15 minutes après 5 échecs', function () {
    $admin = User::factory()->admin()->create();
    $secret = activerDoubleAuthentification($admin);
    connecter($admin);

    $this->post('/two-factor-challenge', ['code' => codeTotp($secret, -20)])->assertSessionHasErrors('code');
    foreach (range(1, 4) as $i) {
        $this->post('/two-factor-challenge', ['code' => '000000'])->assertSessionHasErrors('code');
    }

    $this->post('/two-factor-challenge', ['code' => codeTotp($secret)])->assertStatus(429);
    $this->assertGuest();
});

it('US-108-N3 refuse un code de secours déjà utilisé', function () {
    $admin = User::factory()->admin()->create();
    activerDoubleAuthentification($admin, ['SECOURS-0001', 'SECOURS-0002']);

    connecter($admin);
    $this->post('/two-factor-challenge', ['recovery_code' => 'SECOURS-0001']);
    auth()->logout();

    connecter($admin);
    $this->post('/two-factor-challenge', ['recovery_code' => 'SECOURS-0001'])->assertSessionHasErrors('recovery_code');
    $this->assertGuest();
});

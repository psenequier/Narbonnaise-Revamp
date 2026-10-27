<?php

use App\Models\User;
use Illuminate\Support\Facades\Notification;

beforeEach(fn () => Notification::fake());

it('US-101-P1 connecte un compte actif et redirige vers le calendrier', function () {
    $membre = User::factory()->create();

    $this->post('/login', ['email' => $membre->email, 'password' => MDP_TEST])->assertRedirect('/');
    $this->assertAuthenticatedAs($membre);
    $this->get('/')->assertOk()->assertSee('Calendrier');
});

it('US-101-P2 garde la session 30 jours avec « Rester connecté », pas avant', function () {
    $membre = User::factory()->create();

    $reponse = $this->post('/login', ['email' => $membre->email, 'password' => MDP_TEST, 'remember' => 'on']);

    $cookie = collect($reponse->headers->getCookies())->first(fn ($c) => str_starts_with($c->getName(), 'remember_web_'));
    expect($cookie)->not->toBeNull()
        ->and($cookie->getExpiresTime())->toBeGreaterThanOrEqual(now()->addDays(30)->subMinute()->getTimestamp())
        ->and($cookie->getExpiresTime())->toBeLessThanOrEqual(now()->addDays(30)->addMinute()->getTimestamp());
});

it('US-101-N1 donne le même message et le même code pour un mauvais mot de passe ou un e-mail inconnu', function () {
    $membre = User::factory()->create();

    $mauvais = $this->post('/login', ['email' => $membre->email, 'password' => 'faux']);
    $erreurMauvais = session('errors')->first('email');
    $inconnu = $this->post('/login', ['email' => 'inconnu@example.test', 'password' => 'faux']);
    $erreurInconnu = session('errors')->first('email');

    expect($mauvais->status())->toBe($inconnu->status())
        ->and($erreurMauvais)->toBe($erreurInconnu)
        ->and($erreurMauvais)->not->toBe('');
    $this->assertGuest();
});

it('US-101-N2 refuse un compte désactivé ou une invitation non activée, même avec le bon mot de passe', function () {
    $desactive = User::factory()->create(['email' => 'desactive@example.test']);
    $desactive->forceFill(['actif' => false])->save();
    $invite = User::factory()->invite()->create();

    $this->post('/login', ['email' => $desactive->email, 'password' => MDP_TEST])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => $invite->email, 'password' => ''])->assertSessionHasErrors();
    $this->post('/login', ['email' => $invite->email, 'password' => MDP_TEST])->assertSessionHasErrors('email');
    $this->assertGuest();
});

it('US-101-N3 renvoie 429 à la 6e tentative et refuse le bon mot de passe pendant 15 minutes', function () {
    $membre = User::factory()->create();
    foreach (range(1, 5) as $i) {
        $this->post('/login', ['email' => $membre->email, 'password' => 'faux']);
    }

    $this->post('/login', ['email' => $membre->email, 'password' => MDP_TEST])->assertStatus(429);

    $this->travel(14)->minutes();
    $this->post('/login', ['email' => $membre->email, 'password' => MDP_TEST])->assertStatus(429);

    $this->travel(2)->minutes();
    $this->post('/login', ['email' => $membre->email, 'password' => MDP_TEST])->assertRedirect('/');
    $this->assertAuthenticatedAs($membre);
});

it('US-101-N4 redirige sans session vers la connexion et refuse un formulaire sans jeton CSRF', function () {
    $this->get('/profil')->assertRedirect('/login');

    // La protection CSRF est désactivée par Laravel en environnement de test : on la rétablit.
    $this->app['env'] = 'local';
    try {
        $this->post('/login', ['email' => 'x@example.test', 'password' => 'x'])->assertStatus(419);
    } finally {
        $this->app['env'] = 'testing';
    }
});

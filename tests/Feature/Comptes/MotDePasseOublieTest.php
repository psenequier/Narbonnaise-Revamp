<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password as Courtier;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

beforeEach(function () {
    Notification::fake();
    simulerFuites(['Azerty-Soleil-2024!']);
});

it('US-103-P1 envoie un seul e-mail de réinitialisation pour un e-mail existant', function () {
    $membre = User::factory()->create();

    $this->post('/forgot-password', ['email' => $membre->email])->assertSessionHas('status');

    Notification::assertSentToTimes($membre, ResetPassword::class, 1);
});

it('US-103-N1 affiche le même message pour un e-mail inconnu, sans rien envoyer', function () {
    $membre = User::factory()->create();

    $this->post('/forgot-password', ['email' => $membre->email]);
    $messageConnu = session('status');
    $this->post('/forgot-password', ['email' => 'inconnu@example.test'])->assertSessionHasNoErrors();
    $messageInconnu = session('status');

    expect($messageInconnu)->toBe($messageConnu);
    Notification::assertSentTimes(ResetPassword::class, 1);
});

it('US-103-P2 change le mot de passe avec un lien valide : le nouveau fonctionne, plus l\'ancien', function () {
    $membre = User::factory()->create();
    $jeton = Courtier::createToken($membre);

    $this->post('/reset-password', [
        'token' => $jeton, 'email' => $membre->email,
        'password' => MDP_NOUVEAU, 'password_confirmation' => MDP_NOUVEAU,
    ])->assertSessionHasNoErrors();

    $this->post('/login', ['email' => $membre->email, 'password' => MDP_TEST])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => $membre->email, 'password' => MDP_NOUVEAU])->assertRedirect('/');
});

it('US-103-N2 refuse un lien de plus d\'une heure ou déjà utilisé', function () {
    $membre = User::factory()->create();
    $donnees = fn (string $jeton) => [
        'token' => $jeton, 'email' => $membre->email,
        'password' => MDP_NOUVEAU, 'password_confirmation' => MDP_NOUVEAU,
    ];

    $ancien = Courtier::createToken($membre);
    $this->travel(61)->minutes();
    $this->post('/reset-password', $donnees($ancien))->assertSessionHasErrors('email');

    $jeton = Courtier::createToken($membre);
    $this->post('/reset-password', $donnees($jeton))->assertSessionHasNoErrors();
    $this->post('/reset-password', $donnees($jeton))->assertSessionHasErrors('email');
});

it('US-103-N3 refuse chaque mot de passe faible avec son motif', function (string $mdp, string $motif) {
    $v = Validator::make(['password' => $mdp], ['password' => [Password::defaults()]]);

    expect($v->fails())->toBeTrue()
        ->and(implode(' ', $v->errors()->all()))->toContain($motif);
})->with([
    '11 caractères' => ['Abcdefgh-1!', '12'],
    'sans majuscule' => ['abcdefgh-12345!', 'majuscule'],
    'sans chiffre' => ['Abcdefgh-Ijklm!', 'chiffre'],
    'sans caractère spécial' => ['Abcdefgh12345', 'caractère spécial'],
    'présent dans les fuites' => ['Azerty-Soleil-2024!', 'fuites'],
]);

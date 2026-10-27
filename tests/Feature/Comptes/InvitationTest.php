<?php

use App\Domain\Comptes\Invitations;
use App\Models\User;
use App\Notifications\InvitationCompte;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    simulerFuites();
});

function activerInvitation(string $lien, string $mdp = MDP_NOUVEAU): \Illuminate\Testing\TestResponse
{
    return test()->post($lien, ['password' => $mdp, 'password_confirmation' => $mdp]);
}

it('US-104-P1 active le compte et connecte le membre avec un lien valide', function () {
    $invite = User::factory()->invite()->create();
    $lien = app(Invitations::class)->envoyer($invite);

    Notification::assertSentTo($invite, InvitationCompte::class);
    $this->get($lien)->assertOk()->assertSee('Activer mon compte');
    activerInvitation($lien)->assertRedirect('/');

    $this->assertAuthenticatedAs($invite);
    expect($invite->fresh()->password)->not->toBeNull();
});

it('US-104-P2 invalide l\'ancien lien quand un admin renvoie l\'invitation', function () {
    $invite = User::factory()->invite()->create();
    $ancien = app(Invitations::class)->envoyer($invite);
    $nouveau = app(Invitations::class)->envoyer($invite);

    $this->get($ancien)->assertStatus(410);
    $this->get($nouveau)->assertOk();
});

it('US-104-N1 refuse un lien de plus de 7 jours ou déjà utilisé', function () {
    $invite = User::factory()->invite()->create();
    $perime = app(Invitations::class)->envoyer($invite);
    $this->travel(7)->days();
    $this->travel(1)->minutes();
    $this->get($perime)->assertStatus(410);
    activerInvitation($perime)->assertStatus(410);

    $lien = app(Invitations::class)->envoyer($invite);
    activerInvitation($lien)->assertRedirect('/');
    auth()->logout();
    $this->get($lien)->assertStatus(410);
});

it('US-104-N2 refuse le lien d\'un compte désactivé entre-temps', function () {
    $invite = User::factory()->invite()->create();
    $lien = app(Invitations::class)->envoyer($invite);
    $invite->forceFill(['actif' => false])->save();

    $this->get($lien)->assertStatus(410);
    activerInvitation($lien)->assertStatus(410);
    expect($invite->fresh()->password)->toBeNull();
});

it('US-104-N3 laisse le compte « Invité » si le mot de passe n\'est pas conforme', function () {
    $invite = User::factory()->invite()->create();
    $lien = app(Invitations::class)->envoyer($invite);

    activerInvitation($lien, 'trop-simple')->assertSessionHasErrors('password');

    $this->assertGuest();
    expect($invite->fresh()->password)->toBeNull();
    $this->get($lien)->assertOk(); // le lien reste utilisable
});

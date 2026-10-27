<?php

use App\Models\User;
use App\Notifications\MotDePasseModifie;
use Illuminate\Support\Facades\Notification;

it('US-109-P1 active par défaut les deux e-mails d\'information sur un nouveau compte', function () {
    $membre = User::factory()->create();

    expect($membre->recoit_emails_autres)->toBeTrue()
        ->and($membre->recoit_annonces_attribution)->toBeTrue();
});

it('US-109-P2 retire des destinataires des réservations des autres le membre qui l\'a désactivé', function () {
    $membre = User::factory()->create();
    $autre = User::factory()->create();

    $this->actingAs($membre)->put('/profil/preferences', ['recoit_annonces_attribution' => '1'])->assertRedirect();

    $destinataires = User::recoitReservationsDesAutres()->pluck('id')->all();
    expect($destinataires)->not->toContain($membre->id)->toContain($autre->id)
        ->and($membre->fresh()->recoit_annonces_attribution)->toBeTrue();
});

it('US-109-N1 ignore une requête forgée qui tente de couper les alertes de sécurité', function () {
    Notification::fake();
    simulerFuites();
    $membre = User::factory()->create();
    $avant = $membre->fresh()->getAttributes();

    $this->actingAs($membre)->put('/profil/preferences', [
        'recoit_emails_autres' => '1', 'recoit_annonces_attribution' => '1',
        'recoit_alertes_securite' => '0', 'recoit_emails_mes_creneaux' => '0', 'role' => 'admin', 'actif' => '0',
    ])->assertRedirect();

    $apres = $membre->fresh();
    expect($apres->role)->toBe($avant['role'])->and($apres->actif)->toBeTrue();

    $this->actingAs($apres)->put('/user/password', [
        'current_password' => MDP_TEST, 'password' => MDP_NOUVEAU, 'password_confirmation' => MDP_NOUVEAU,
    ]);
    Notification::assertSentTo($membre, MotDePasseModifie::class);
});

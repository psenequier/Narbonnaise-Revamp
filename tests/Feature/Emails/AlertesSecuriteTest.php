<?php

use App\Models\User;
use App\Notifications\CompteGoogleModifie;
use App\Notifications\EmailModifie;
use App\Notifications\MotDePasseModifie;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    simulerFuites();
    $this->travelTo('2026-11-02 10:05:00');
});

it('US-505-P1 alerte sur un changement de mot de passe, d\'e-mail ou de liaison Google, avec date, heure et lien de signalement', function () {
    $membre = User::factory()->create(['email' => 'membre@example.test']);
    $this->actingAs($membre)->put('/user/password', [
        'current_password' => MDP_TEST, 'password' => MDP_NOUVEAU, 'password_confirmation' => MDP_NOUVEAU,
    ]);
    $this->actingAs($membre)->delete('/profil/google');

    $alerte = null;
    Notification::assertSentTo($membre, MotDePasseModifie::class, function ($n) use (&$alerte, $membre) {
        $alerte = $n->toMail($membre);

        return true;
    });
    Notification::assertSentTo($membre, CompteGoogleModifie::class);
    $texte = implode(' ', $alerte->introLines);
    expect($texte)->toContain('lundi 2 novembre 2026 à 11h05')
        ->and($alerte->actionUrl)->toStartWith('mailto:');
});

it('US-505-P2 envoie l\'alerte de changement d\'e-mail à l\'ancienne adresse', function () {
    $membre = User::factory()->create(['email' => 'ancienne@example.test']);
    $membre->forceFill(['email_en_attente' => 'nouvelle@example.test'])->save();
    $lien = Illuminate\Support\Facades\URL::temporarySignedRoute('profil.email.confirmer', now()->addDay(), [
        'user' => $membre->id, 'empreinte' => hash('sha256', 'nouvelle@example.test'),
    ]);

    $this->actingAs($membre)->get($lien)->assertRedirect('/profil');

    Notification::assertSentOnDemand(EmailModifie::class, fn ($n, $c, $d) => $d->routes['mail'] === 'ancienne@example.test');
});

it('US-505-N1 envoie l\'alerte même si toutes les préférences sont désactivées', function () {
    $membre = User::factory()->create();
    $membre->forceFill(['recoit_emails_autres' => false, 'recoit_annonces_attribution' => false])->save();

    $this->actingAs($membre)->put('/user/password', [
        'current_password' => MDP_TEST, 'password' => MDP_NOUVEAU, 'password_confirmation' => MDP_NOUVEAU,
    ]);

    Notification::assertSentTo($membre, MotDePasseModifie::class);
});

it('US-505-N2 ne contient jamais le mot de passe ni un lien qui agit sur le compte sans connexion', function () {
    $membre = User::factory()->create();
    $this->actingAs($membre)->put('/user/password', [
        'current_password' => MDP_TEST, 'password' => MDP_NOUVEAU, 'password_confirmation' => MDP_NOUVEAU,
    ]);

    Notification::assertSentTo($membre, MotDePasseModifie::class, function ($n) use ($membre) {
        $html = (string) $n->toMail($membre)->render();
        preg_match_all('/href="([^"]+)"/', $html, $liens);
        $actifs = array_filter($liens[1], fn ($l) => ! str_starts_with($l, 'mailto:') && rtrim($l, '/') !== rtrim((string) config('app.url'), '/'));

        return ! str_contains($html, MDP_NOUVEAU) && ! str_contains($html, MDP_TEST) && $actifs === [];
    });
});

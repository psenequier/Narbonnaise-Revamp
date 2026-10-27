<?php

use App\Models\Journal;
use App\Models\User;
use App\Notifications\ConfirmationEmail;
use App\Notifications\EmailModifie;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(fn () => Notification::fake());

function modifierProfil(User $membre, array $champs): \Illuminate\Testing\TestResponse
{
    return test()->actingAs($membre)->put('/user/profile-information', $champs + [
        'prenom' => $membre->prenom, 'nom' => $membre->nom, 'email' => $membre->email,
    ]);
}

it('US-105-P1 enregistre le nom et le prénom et les trace dans le journal', function () {
    $membre = User::factory()->create();

    modifierProfil($membre, ['prenom' => 'Camille', 'nom' => 'Martin'])->assertSessionHasNoErrors();

    expect($membre->fresh())->prenom->toBe('Camille')->nom->toBe('Martin')
        ->and(Journal::where('action', 'profil.modifie')->where('objet_id', $membre->id)->exists())->toBeTrue();
});

it('US-105-P2 fait de l\'e-mail confirmé l\'identifiant et alerte l\'ancienne adresse', function () {
    $membre = User::factory()->create(['email' => 'ancienne@example.test']);

    modifierProfil($membre, ['email' => 'nouvelle@example.test'])->assertSessionHasNoErrors();

    $lien = null;
    Notification::assertSentOnDemand(ConfirmationEmail::class, function ($n, $canaux, $destinataire) use (&$lien) {
        $lien = $n->lien;

        return $destinataire->routes['mail'] === 'nouvelle@example.test';
    });

    $this->actingAs($membre->fresh())->get($lien)->assertRedirect('/profil');

    expect($membre->fresh()->email)->toBe('nouvelle@example.test');
    Notification::assertSentOnDemand(EmailModifie::class, fn ($n, $c, $d) => $d->routes['mail'] === 'ancienne@example.test');
});

it('US-105-N1 garde l\'ancien e-mail comme identifiant tant que le lien n\'est pas cliqué', function () {
    $membre = User::factory()->create(['email' => 'ancienne@example.test']);
    modifierProfil($membre, ['email' => 'nouvelle@example.test']);
    auth()->logout();

    expect($membre->fresh()->email)->toBe('ancienne@example.test');
    $this->post('/login', ['email' => 'nouvelle@example.test', 'password' => MDP_TEST])->assertSessionHasErrors('email');
    $this->post('/login', ['email' => 'ancienne@example.test', 'password' => MDP_TEST])->assertRedirect('/');
});

it('US-105-N2 refuse un e-mail déjà utilisé par un autre compte', function () {
    User::factory()->create(['email' => 'pris@example.test']);
    $membre = User::factory()->create();

    modifierProfil($membre, ['email' => 'pris@example.test'])->assertSessionHasErrorsIn('updateProfileInformation', 'email');
    Notification::assertNothingSent();
});

it('US-105-N3 refuse un nom vide ou un e-mail mal formé', function () {
    $membre = User::factory()->create();

    modifierProfil($membre, ['nom' => ''])->assertSessionHasErrorsIn('updateProfileInformation', 'nom');
    modifierProfil($membre, ['email' => 'pas-un-email'])->assertSessionHasErrorsIn('updateProfileInformation', 'email');
    expect($membre->fresh()->nom)->not->toBe('');
});

it('US-105-N4 enregistre un nom contenant <script> mais l\'affiche échappé', function () {
    $membre = User::factory()->create();
    $piege = '<script>alert(1)</script>';

    modifierProfil($membre, ['prenom' => $piege, 'nom' => $piege])->assertSessionHasNoErrors();

    expect($membre->fresh()->nom)->toBe($piege);
    $this->actingAs($membre->fresh())->get('/profil')
        ->assertOk()
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertDontSee($piege, false);
});

it('US-105-N5 interdit de confirmer un changement d\'e-mail sur le compte d\'un autre (403)', function () {
    $victime = User::factory()->create();
    $victime->forceFill(['email_en_attente' => 'pirate@example.test'])->save();
    $pirate = User::factory()->create();
    $lien = URL::temporarySignedRoute('profil.email.confirmer', now()->addDay(), [
        'user' => $victime->id, 'empreinte' => hash('sha256', 'pirate@example.test'),
    ]);

    $this->actingAs($pirate)->get($lien)->assertForbidden();
    expect($victime->fresh()->email)->not->toBe('pirate@example.test');

    // Le formulaire de profil ignore tout identifiant envoyé : il ne touche que le membre connecté.
    $prenomAvant = $victime->prenom;
    $this->actingAs($pirate)->put('/user/profile-information', [
        'id' => $victime->id, 'user_id' => $victime->id,
        'prenom' => 'Pirate', 'nom' => 'Pirate', 'email' => $pirate->email,
    ]);
    expect($victime->fresh()->prenom)->toBe($prenomAvant);
});

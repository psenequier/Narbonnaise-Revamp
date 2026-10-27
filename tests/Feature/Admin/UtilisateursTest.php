<?php

use App\Domain\Comptes\Invitations;
use App\Models\Journal;
use App\Models\User;
use App\Notifications\InvitationCompte;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->travelTo('2026-11-02 10:00:00');
    $this->admin = adminAvec2fa(['prenom' => 'Ada', 'nom' => 'Admin']);
    $this->membre = User::factory()->create(['prenom' => 'Marc', 'nom' => 'Membre', 'email' => 'marc@example.test']);
});

function nouveauCompte(array $autres = []): array
{
    return $autres + ['prenom' => 'Zoé', 'nom' => 'Nouvelle', 'email' => 'zoe@example.test', 'role' => 'membre'];
}

it('US-601-P1 crée un compte « Invité » et envoie l\'invitation', function () {
    $this->actingAs($this->admin)->post('/admin/utilisateurs', nouveauCompte())->assertSessionHasNoErrors()->assertRedirect();

    $zoe = User::where('email', 'zoe@example.test')->sole();
    expect($zoe->statut())->toBe('Invité')->and($zoe->role)->toBe('membre')->and($zoe->password)->toBeNull();
    Notification::assertSentTo($zoe, InvitationCompte::class);
    expect(Journal::where('action', 'compte.cree')->where('user_id', $this->admin->id)->exists())->toBeTrue();
});

it('US-601-P2 renvoie une invitation qui invalide le lien précédent', function () {
    $this->actingAs($this->admin)->post('/admin/utilisateurs', nouveauCompte());
    $zoe = User::where('email', 'zoe@example.test')->sole();
    $liens = [];
    Notification::assertSentTo($zoe, InvitationCompte::class, function ($n) use (&$liens) {
        $liens[] = $n->lien;

        return true;
    });

    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$zoe->id}/invitation")->assertSessionHasNoErrors();
    Notification::assertSentToTimes($zoe, InvitationCompte::class, 2);
    $nouveau = null;
    Notification::assertSentTo($zoe, InvitationCompte::class, function ($n) use (&$nouveau) {
        $nouveau = $n->lien;

        return true;
    });

    auth()->logout();
    $this->get($liens[0])->assertStatus(410);
    $this->get($nouveau)->assertOk();
});

it('US-601-N1 refuse un e-mail déjà utilisé, quelle que soit la casse', function () {
    $this->actingAs($this->admin)->post('/admin/utilisateurs', nouveauCompte(['email' => 'MARC@Example.TEST']))
        ->assertSessionHasErrors('email');

    expect(User::where('email', 'like', 'marc@%')->count())->toBe(1);
});

it('US-601-N2 refuse un rôle autre que Membre ou Administrateur', function (string $role) {
    $this->actingAs($this->admin)->post('/admin/utilisateurs', nouveauCompte(['role' => $role]))->assertSessionHasErrors('role');

    expect(User::where('email', 'zoe@example.test')->exists())->toBeFalse();
})->with(['superadmin', 'root', '']);

it('US-601-N3 n\'a aucun champ de mot de passe et ignore un mot de passe envoyé', function () {
    $this->actingAs($this->admin)->get('/admin/utilisateurs')->assertOk()->assertDontSee('type="password"', false)->assertDontSee('name="password"', false);

    $this->actingAs($this->admin)->post('/admin/utilisateurs', nouveauCompte(['password' => 'Mot-de-passe-impose-1!', 'actif' => '0']));

    $zoe = User::where('email', 'zoe@example.test')->sole();
    expect($zoe->password)->toBeNull()->and($zoe->actif)->toBeTrue();
});

it('US-601-N4 renvoie 403 à un non-admin', function () {
    $this->actingAs($this->membre)->get('/admin/utilisateurs')->assertForbidden();
    $this->actingAs($this->membre)->post('/admin/utilisateurs', nouveauCompte())->assertForbidden();
    $this->actingAs($this->membre)->post("/admin/utilisateurs/{$this->membre->id}/invitation")->assertForbidden();

    expect(User::where('email', 'zoe@example.test')->exists())->toBeFalse();
});

it('US-602-P1 ferme les sessions du compte désactivé à la requête suivante, et garde ses réservations passées', function () {
    creerCreneaux('2026-11-01', '2026-11-30');
    reserverPour($this->membre, ['2026-11-03-N']);
    $this->travelTo('2026-11-10 10:00:00');
    $this->actingAs($this->membre)->get('/')->assertOk();

    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->membre->id}/desactiver")->assertSessionHasNoErrors();
    $this->actingAs($this->membre->fresh())->get('/')->assertRedirect('/login');
    $this->assertGuest();

    $this->actingAs($this->admin)->get('/jour/2026-11-03')->assertSee('Marc Membre');
});

it('US-602-P2 liste à l\'admin les réservations futures du compte', function () {
    creerCreneaux('2026-11-01', '2026-11-30');
    $passee = reserverPour($this->membre, ['2026-11-03-N']);
    $future = reserverPour($this->membre, ['2026-11-20-N']);
    $this->travelTo('2026-11-10 10:00:00');

    $this->actingAs($this->admin)->get("/admin/utilisateurs/{$this->membre->id}")->assertOk()
        ->assertSee('data-reservation="'.$future->id.'"', false)
        ->assertDontSee('data-reservation="'.$passee->id.'"', false);
});

it('US-602-P3 permet de se reconnecter après réactivation', function () {
    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->membre->id}/desactiver");
    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->membre->id}/reactiver")->assertSessionHasNoErrors();
    auth()->logout();

    $this->post('/login', ['email' => 'marc@example.test', 'password' => MDP_TEST])->assertRedirect('/');
    $this->assertAuthenticatedAs($this->membre);
});

it('US-602-N1 refuse de désactiver ou rétrograder le dernier admin actif', function () {
    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->admin->id}/desactiver")->assertSessionHasErrors('compte');
    $this->actingAs($this->admin)->put("/admin/utilisateurs/{$this->admin->id}/role", ['role' => 'membre'])->assertSessionHasErrors('compte');

    expect($this->admin->fresh()->actif)->toBeTrue()
        ->and($this->admin->fresh()->role)->toBe('admin');

    $second = adminAvec2fa();
    $this->actingAs($this->admin)->put("/admin/utilisateurs/{$second->id}/role", ['role' => 'membre'])->assertSessionHasNoErrors();
    expect($second->fresh()->role)->toBe('membre');
});

it('US-602-N2 interdit la connexion d\'un compte désactivé, par mot de passe comme par Google', function () {
    $this->membre->forceFill(['google_id' => 'g-marc'])->save();
    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->membre->id}/desactiver");
    auth()->logout();
    config(['services.google.client_id' => 'x', 'services.google.client_secret' => 'y', 'services.google.redirect' => 'http://localhost/auth/google/retour']);

    $this->post('/login', ['email' => 'marc@example.test', 'password' => MDP_TEST])->assertSessionHasErrors('email');
    simulerGoogle('g-marc', 'marc@example.test');
    $this->get('/auth/google/retour')->assertRedirect('/login');
    $this->assertGuest();
});

it('US-602-N3 renvoie 403 à un non-admin', function () {
    $autre = User::factory()->create();

    $this->actingAs($this->membre)->post("/admin/utilisateurs/{$autre->id}/desactiver")->assertForbidden();
    $this->actingAs($this->membre)->post("/admin/utilisateurs/{$autre->id}/reactiver")->assertForbidden();
    $this->actingAs($this->membre)->put("/admin/utilisateurs/{$this->membre->id}/role", ['role' => 'admin'])->assertForbidden();

    expect($autre->fresh()->actif)->toBeTrue()->and($this->membre->fresh()->role)->toBe('membre');
});

it('US-603-P1 envoie au membre un lien valable 1 heure, tracé avec le nom de l\'admin', function () {
    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->membre->id}/reinitialisation")->assertSessionHasNoErrors();

    $jeton = null;
    Notification::assertSentTo($this->membre, ResetPassword::class, function ($n) use (&$jeton) {
        $jeton = $n->token;

        return true;
    });
    expect(Journal::where('action', 'compte.lien_reinitialisation')->sole()->user_id)->toBe($this->admin->id)
        ->and(config('auth.passwords.users.expire'))->toBe(60);

    auth()->logout();
    simulerFuites();
    $this->travel(61)->minutes();
    $this->post('/reset-password', ['token' => $jeton, 'email' => 'marc@example.test', 'password' => MDP_NOUVEAU, 'password_confirmation' => MDP_NOUVEAU])
        ->assertSessionHasErrors('email');
});

it('US-603-N1 ne montre jamais le lien à l\'admin : ni dans la réponse, ni dans le journal, ni dans les traces', function () {
    $traces = [];
    \Illuminate\Support\Facades\Log::listen(function ($message) use (&$traces) {
        $traces[] = $message->message.' '.json_encode($message->context);
    });

    $reponse = $this->actingAs($this->admin)->followingRedirects()->post("/admin/utilisateurs/{$this->membre->id}/reinitialisation");

    $jeton = null;
    Notification::assertSentTo($this->membre, ResetPassword::class, function ($n) use (&$jeton) {
        $jeton = $n->token;

        return true;
    });
    expect($reponse->getContent())->not->toContain($jeton)->not->toContain('reset-password')
        ->and(json_encode(Journal::all()->toArray()))->not->toContain($jeton)
        ->and(implode("\n", $traces))->not->toContain($jeton);
});

it('US-603-N2 renvoie 403 à un non-admin', function () {
    $this->actingAs($this->membre)->post("/admin/utilisateurs/{$this->admin->id}/reinitialisation")->assertForbidden();

    Notification::assertNothingSent();
});

<?php

use App\Domain\Emails\BoiteEnvoi;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2027-01-15 10:00:00');
    $this->admin = unAdmin(['prenom' => 'Ada', 'nom' => 'Admin', 'email' => 'ada@example.test']);
    $this->membre = User::factory()->create(['prenom' => 'Marc', 'nom' => 'Membre', 'email' => 'marc@example.test', 'google_id' => 'google-123']);
});

/** Réservation insérée directement (passée ou future), comme dans les tests de statistiques. */
function reservationDirecte(User $membre, string $date): int
{
    $id = DB::table('reservations')->insertGetId([
        'user_id' => $membre->id, 'mode' => 'ouverte', 'nombre_personnes' => 3, 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('creneaux')->insertOrIgnore(['date' => $date, 'type' => 'N', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('reservation_creneau')->insert(['reservation_id' => $id, 'creneau_id' => DB::table('creneaux')->where('date', $date)->where('type', 'N')->value('id')]);

    return $id;
}

function demanderEtSupprimer(object $test): void
{
    $test->actingAs($test->membre)->post('/profil/suppression')->assertRedirect();
    $test->actingAs($test->admin)->post("/admin/utilisateurs/{$test->membre->id}/supprimer")->assertSessionHasNoErrors();
}

it('US-110-P1 la demande de suppression prévient les admins', function () {
    $this->actingAs($this->membre)->post('/profil/suppression')->assertRedirect()->assertSessionHas('status');

    expect($this->membre->fresh()->suppression_demandee_le)->not->toBeNull()
        ->and(Journal::where('action', 'compte.suppression_demandee')->where('user_id', $this->membre->id)->exists())->toBeTrue();
    $mail = DB::table('emails_sortants')->where('gabarit', 'admin.suppression_demandee')->sole();
    expect(json_decode($mail->destinataires, true)['a'])->toBe(['ada@example.test'])
        ->and(json_decode($mail->donnees, true)['prenom'])->toBe('Marc');
    $this->actingAs($this->admin)->get("/admin/utilisateurs/{$this->membre->id}")->assertOk()->assertSee('Suppression demandée le');
});

it('US-110-P2 efface nom, e-mail, mot de passe et compte Google ; garde le prénom et les réservations passées', function () {
    $passee = reservationDirecte($this->membre, '2026-12-20');
    $avant = DB::table('v_occupation')->where('etat', '!=', 'libre')->count();

    demanderEtSupprimer($this);

    $m = $this->membre->fresh();
    expect($m->prenom)->toBe('Marc')->and($m->nom)->toBe('')->and($m->email)->not->toContain('marc')
        ->and($m->email)->toEndWith('.invalid')->and($m->password)->toBeNull()->and($m->google_id)->toBeNull()
        ->and($m->actif)->toBeFalse()->and($m->statut())->toBe('Supprimé')
        ->and(DB::table('reservations')->where('id', $passee)->whereNull('annulee_le')->exists())->toBeTrue()
        ->and(DB::table('v_occupation')->where('etat', '!=', 'libre')->count())->toBe($avant);
    $this->actingAs($this->admin)->get("/admin/utilisateurs/{$this->membre->id}")->assertOk()
        ->assertSee('Compte supprimé')->assertDontSee('marc@example.test')->assertDontSee('Supprimer le compte');
});

it('US-110-N4 la confirmation annule les réservations à venir ; un compte supprimé ne se rouvre pas', function () {
    $passee = reservationDirecte($this->membre, '2027-01-10');
    $future = reservationDirecte($this->membre, '2027-02-10');
    $this->actingAs($this->membre)->post('/profil/suppression')->assertRedirect();
    $this->actingAs($this->admin)->get("/admin/utilisateurs/{$this->membre->id}")
        ->assertSee('Ses réservations à venir seront annulées', false);

    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->membre->id}/supprimer")->assertSessionHasNoErrors();

    expect($this->membre->fresh()->supprime_le)->not->toBeNull()
        ->and(DB::table('reservations')->where('id', $future)->value('annulee_le'))->not->toBeNull()
        ->and(DB::table('reservations')->where('id', $passee)->value('annulee_le'))->toBeNull();
    $annulation = Journal::where('action', 'reservation.annulee')->where('objet_id', $future)->sole();
    expect($annulation->details['motif_admin'] ?? null)->toBe('Compte supprimé à la demande du membre');
    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->membre->id}/reactiver")->assertSessionHasErrors();
    expect($this->membre->fresh()->actif)->toBeFalse();
});

it('US-110-P3 efface nom et e-mail du détail du journal, sans toucher aux autres entrées', function () {
    Journal::ecrire('compte.email_modifie', $this->membre, ['avant' => ['email' => 'marc@example.test', 'nom' => 'Membre'], 'apres' => ['email' => 'marc2@example.test']], $this->membre->id);
    Journal::ecrire('reservation.action_admin', null, ['motif' => 'Échange demandé par Marc Membre (marc@example.test)'], $this->admin->id);
    $autre = Journal::ecrire('lignee.modifiee', null, ['nom' => 'Membre', 'motif' => 'Sans rapport'], $this->admin->id);

    demanderEtSupprimer($this);

    $tout = DB::table('journal')->pluck('details')->implode(' ');
    expect($tout)->not->toContain('marc@example.test')->not->toContain('Marc Membre')
        ->and(json_decode(DB::table('journal')->where('action', 'reservation.action_admin')->value('details'), true)['motif'])
        ->toBe('Échange demandé par Marc ([adresse effacée])')
        ->and(json_decode(DB::table('journal')->where('id', $autre->id)->value('details'), true))->toBe(['nom' => 'Membre', 'motif' => 'Sans rapport'])
        ->and(Journal::where('action', 'compte.supprime')->where('objet_id', $this->membre->id)->exists())->toBeTrue();
});

it('US-110-P4 retire son adresse de la boîte d\'envoi et ne lui envoie plus rien', function () {
    $boite = app(BoiteEnvoi::class);
    $seul = $boite->deposer('reservation.confirmation', ['marc@example.test'], [], ['prenom' => 'Marc']);
    $groupe = $boite->deposer('reservation.information', [], ['ada@example.test', 'marc@example.test'], ['texte' => 'Bonjour']);

    demanderEtSupprimer($this);

    expect(DB::table('emails_sortants')->where('id', $seul)->exists())->toBeFalse()
        ->and(json_decode(DB::table('emails_sortants')->where('id', $groupe)->value('destinataires'), true))->toBe(['a' => [], 'cci' => ['ada@example.test']])
        ->and(DB::table('emails_sortants')->where('destinataires', 'like', '%marc@example.test%')->exists())->toBeFalse()
        ->and(User::query()->recoitReservationsDesAutres()->whereKey($this->membre->id)->exists())->toBeFalse();
});

it('US-110-N1 un membre ne peut demander que la suppression de son propre compte', function () {
    $autre = User::factory()->create();

    $this->actingAs($this->membre)->post('/profil/suppression', ['user_id' => $autre->id, 'user' => $autre->id]);
    expect($autre->fresh()->suppression_demandee_le)->toBeNull()->and($this->membre->fresh()->suppression_demandee_le)->not->toBeNull();
    $this->actingAs($this->membre)->post("/admin/utilisateurs/{$autre->id}/supprimer")->assertForbidden();
    expect($autre->fresh()->supprime_le)->toBeNull();
});

it('US-110-N2 refuse la suppression du dernier admin actif', function () {
    $this->actingAs($this->admin)->post('/profil/suppression');

    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->admin->id}/supprimer")->assertSessionHasErrors('compte');
    expect($this->admin->fresh()->supprime_le)->toBeNull()->and($this->admin->fresh()->email)->toBe('ada@example.test');
});

it('US-110-N3 après suppression, l\'ancien e-mail ne permet plus de se connecter, et le compte ne se rouvre pas', function () {
    demanderEtSupprimer($this);
    auth()->logout();

    $this->post('/login', ['email' => 'marc@example.test', 'password' => MDP_TEST])->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->actingAs($this->admin)->post("/admin/utilisateurs/{$this->membre->id}/reactiver")->assertSessionHasErrors('compte');
    expect($this->membre->fresh()->actif)->toBeFalse();
});

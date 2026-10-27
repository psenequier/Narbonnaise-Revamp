<?php

use App\Mail\MessageSite;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-02 10:00:00');
    creerCreneaux('2026-11-01', '2026-11-30');
    $this->admin = User::factory()->admin()->create(['prenom' => 'Ada', 'nom' => 'Admin']);
    activerDoubleAuthentification($this->admin);
    $this->alice = User::factory()->create(['email' => 'alice@example.test']);
    $this->resa = reserverPour($this->alice, ['2026-11-14-N']);
});

function corpsModification(array $autres = []): array
{
    return $autres + ['debut' => '2026-11-14', 'fin' => '2026-11-15', 'creneaux' => ['2026-11-14-N', '2026-11-15-J'], 'mode' => 'ouverte', 'nombre_personnes' => 4];
}

it('US-309-P1 laisse un admin modifier ou annuler avec un motif : e-mail au réservant et journal', function () {
    $this->actingAs($this->admin)->put("/reservations/{$this->resa->id}", corpsModification(['motif' => 'Doublon avec le planning']))
        ->assertSessionHasNoErrors()->assertRedirect('/jour/2026-11-14');
    $this->actingAs($this->admin)->delete("/reservations/{$this->resa->id}", ['motif' => 'Fermeture imprévue'])
        ->assertSessionHasNoErrors();

    $mails = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'reservation.action_admin' && $m->hasTo('alice@example.test'));
    expect($mails)->toHaveCount(2)
        ->and($mails->first()->render())->toContain('Ada Admin')->toContain('Doublon avec le planning')
        ->and($mails->last()->render())->toContain('Fermeture imprévue')
        ->and($this->resa->fresh()->annulee_le)->not->toBeNull()
        ->and(Journal::where('action', 'reservation.modifiee')->sole()->details['motif_admin'])->toBe('Doublon avec le planning')
        ->and(Journal::where('action', 'reservation.annulee')->sole()->user_id)->toBe($this->admin->id);
});

it('US-309-N1 refuse une action d\'admin sans motif', function () {
    $this->actingAs($this->admin)->put("/reservations/{$this->resa->id}", corpsModification())->assertSessionHasErrors('motif');
    $this->actingAs($this->admin)->delete("/reservations/{$this->resa->id}")->assertSessionHasErrors('motif');

    expect($this->resa->fresh()->annulee_le)->toBeNull()
        ->and($this->resa->fresh()->creneaux)->toHaveCount(1);
    Mail::assertNotSent(MessageSite::class, fn ($m) => $m->gabarit === 'reservation.action_admin');
});

it('US-309-N2 renvoie 403 à un membre non admin qui appelle la même action', function () {
    $bruno = User::factory()->create();

    $this->actingAs($bruno)->put("/reservations/{$this->resa->id}", corpsModification(['motif' => 'Je veux sa place']))->assertForbidden();
    $this->actingAs($bruno)->delete("/reservations/{$this->resa->id}", ['motif' => 'Je veux sa place'])->assertForbidden();

    expect($this->resa->fresh()->annulee_le)->toBeNull();
});

it('refuse l\'action d\'un admin sans double authentification (403)', function () {
    $admin = User::factory()->admin()->create();

    $this->actingAs($admin)->delete("/reservations/{$this->resa->id}", ['motif' => 'Test'])->assertForbidden();
});

it('montre à l\'admin le bouton d\'action et le champ motif', function () {
    $this->actingAs($this->admin)->get('/jour/2026-11-14')->assertOk()->assertSee('Action admin');
    $this->actingAs($this->admin)->get("/reservations/{$this->resa->id}/modifier")->assertOk()->assertSee('name="motif"', false);
    $this->actingAs($this->alice)->get('/jour/2026-11-14')->assertDontSee('Action admin');
});

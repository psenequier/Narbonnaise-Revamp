<?php

use App\Domain\Vacances\PeriodesScolaires;
use App\Mail\MessageSite;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-02 10:00:00');
    ouvrirAnnee2026();
    $this->admin = adminAvec2fa(['prenom' => 'Ada', 'nom' => 'Admin', 'email' => 'admin@example.test']);
    $this->membre = User::factory()->create(['prenom' => 'Marc', 'email' => 'membre@example.test']);
    $this->resa = reserverPour($this->membre, ['2026-12-15-N', '2026-12-16-J']);
    Mail::fake();
});

/** La source avance Noël d'une semaine : la réservation du 15 décembre se retrouve dans les vacances. */
function noelAvance(): void
{
    simulerCalendrier(noel: ['2026-12-11T23:00:00+00:00', '2027-01-03T23:00:00+00:00']);
    app(PeriodesScolaires::class)->synchroniserAnnee('2026-2027');
}

it('US-407-P1 passe « En conflit » une réservation englobée par de nouvelles dates et alerte les admins', function () {
    noelAvance();

    expect($this->resa->fresh()->en_conflit_le)->not->toBeNull()
        ->and(DB::table('creneaux')->where('date', '2026-12-15')->where('type', 'N')->value('periode_id'))->toBe(periode('noel')->id);
    $alerte = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'admin.conflits_vacances')->sole();
    expect($alerte->hasTo('admin@example.test'))->toBeTrue()->and($alerte->render())->toContain('Marc');
    $this->actingAs($this->admin)->get('/admin/vacances')->assertSee('data-conflit="'.$this->resa->id.'"', false);
});

it('US-407-P2 laisse un admin maintenir ou annuler avec un motif, et prévient le réservant', function () {
    $autre = reserverPour($this->membre, ['2026-12-13-N']);
    noelAvance();
    Mail::fake();

    $this->actingAs($this->admin)->post("/admin/conflits/{$this->resa->id}", ['decision' => 'maintenir', 'motif' => 'Séjour prévu de longue date'])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post("/admin/conflits/{$autre->id}", ['decision' => 'annuler', 'motif' => 'Vacances attribuées'])
        ->assertSessionHasNoErrors();

    expect($this->resa->fresh()->en_conflit_le)->toBeNull()
        ->and($this->resa->fresh()->conflit_maintenu_le)->not->toBeNull()
        ->and($this->resa->fresh()->annulee_le)->toBeNull()
        ->and($autre->fresh()->annulee_le)->not->toBeNull();
    $mails = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'reservation.action_admin' && $m->hasTo('membre@example.test'));
    expect($mails)->toHaveCount(2)
        ->and($mails->first()->render())->toContain('Séjour prévu de longue date')
        ->and($mails->last()->render())->toContain('Vacances attribuées');
    expect(app(App\Domain\Calendrier\ControleCoherence::class)->anomalies())->toBe([]);
});

it('US-407-N1 n\'annule aucune réservation automatiquement', function () {
    $actives = Reservation::query()->actives()->count();

    noelAvance();

    expect(Reservation::query()->actives()->count())->toBe($actives);
});

it('US-407-N2 refuse un arbitrage sans motif', function () {
    noelAvance();

    $this->actingAs($this->admin)->post("/admin/conflits/{$this->resa->id}", ['decision' => 'annuler'])->assertSessionHasErrors('motif');
    $this->actingAs($this->admin)->post("/admin/conflits/{$this->resa->id}", ['decision' => 'annuler', 'motif' => ''])->assertSessionHasErrors('motif');

    expect($this->resa->fresh()->annulee_le)->toBeNull()->and($this->resa->fresh()->en_conflit_le)->not->toBeNull();
});

it('US-408-P1 applique une période corrigée à la main aux créneaux et la marque « manuelle »', function () {
    $this->actingAs($this->admin)->put('/admin/periodes/'.periode('hiver')->id, ['premiere_nuit' => '2027-02-05', 'dernier_jour' => '2027-02-21'])
        ->assertSessionHasNoErrors();

    $hiver = periode('hiver');
    $dans = fn (string $date, string $type) => DB::table('creneaux')->where('date', $date)->where('type', $type)->value('periode_id') === $hiver->id;
    expect($hiver->manuelle)->toBeTrue()
        ->and($hiver->premiere_nuit->format('Y-m-d'))->toBe('2027-02-05')
        ->and($dans('2027-02-05', 'N'))->toBeTrue()
        ->and($dans('2027-02-21', 'J'))->toBeTrue()
        ->and($dans('2027-02-21', 'N'))->toBeFalse()
        ->and($dans('2027-02-25', 'J'))->toBeFalse()
        ->and(DB::table('creneaux')->where('periode_id', $hiver->id)->count())->toBe(32);
});

it('US-408-P2 signale aux admins l\'écart entre la source et une période corrigée', function () {
    $this->actingAs($this->admin)->put('/admin/periodes/'.periode('hiver')->id, ['premiere_nuit' => '2027-02-05', 'dernier_jour' => '2027-02-21']);
    Mail::fake();
    simulerCalendrier(hiver: ['2027-02-19T23:00:00+00:00', '2027-03-07T23:00:00+00:00']);

    app(PeriodesScolaires::class)->synchroniserAnnee('2026-2027');

    $alerte = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'admin.vacances_modifiees')->sole();
    expect($alerte->render())->toContain('corrigée à la main')->toContain('19/02/2027');
});

it('US-408-N1 n\'écrase jamais une période corrigée à la main', function () {
    $this->actingAs($this->admin)->put('/admin/periodes/'.periode('hiver')->id, ['premiere_nuit' => '2027-02-05', 'dernier_jour' => '2027-02-21']);

    simulerCalendrier();
    app(PeriodesScolaires::class)->synchroniserAnnee('2026-2027');

    expect(periode('hiver')->premiere_nuit->format('Y-m-d'))->toBe('2027-02-05')
        ->and(periode('hiver')->dernier_jour->format('Y-m-d'))->toBe('2027-02-21');
});

it('US-408-N2 refuse une date de fin antérieure à la date de début', function () {
    $this->actingAs($this->admin)->put('/admin/periodes/'.periode('hiver')->id, ['premiere_nuit' => '2027-02-21', 'dernier_jour' => '2027-02-05'])
        ->assertSessionHasErrors('dernier_jour');

    expect(periode('hiver')->manuelle)->toBeFalse();
});

it('US-408-N3 renvoie 403 à un non-admin qui corrige une période', function () {
    $this->actingAs($this->membre)->put('/admin/periodes/'.periode('hiver')->id, ['premiere_nuit' => '2027-02-05', 'dernier_jour' => '2027-02-21'])
        ->assertForbidden();

    expect(periode('hiver')->manuelle)->toBeFalse();
});

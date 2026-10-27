<?php

use App\Mail\MessageSite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-02 10:00:00');
    creerCreneaux('2026-11-01', '2026-11-30');
    $this->admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
    $this->alice = User::factory()->create();
    $this->bruno = User::factory()->create();
});

it('US-912-P1 ne signale rien et n\'envoie aucun e-mail sur des données cohérentes', function () {
    reserverPour($this->alice, ['2026-11-14-N']);
    reserverPour($this->bruno, ['2026-11-14-N']);
    reserverPour($this->bruno, ['2026-11-20-N'], 'exclusive');

    $this->artisan('creneaux:verifier')->expectsOutput('Aucune anomalie.')->assertSuccessful();
    Mail::assertNothingSent();
});

it('US-912-N1 détecte un état forcé en base et alerte les admins avec le détail', function () {
    reserverPour($this->alice, ['2026-11-14-N'], 'exclusive');
    $autre = reserverPour($this->bruno, ['2026-11-15-N']);
    // Contournement du service : on force une seconde réservation sur le créneau exclusif.
    $creneau = DB::table('creneaux')->where('date', '2026-11-14')->where('type', 'N')->value('id');
    DB::table('reservation_creneau')->insert(['reservation_id' => $autre->id, 'creneau_id' => $creneau]);

    $this->artisan('creneaux:verifier')->assertFailed();

    $alerte = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'admin.anomalies')->sole();
    expect($alerte->hasTo('admin@example.test'))->toBeTrue()
        ->and($alerte->render())->toContain('2026-11-14 N')->toContain('exclusif partagé');
});

it('US-912-N2 ne corrige rien automatiquement', function () {
    reserverPour($this->alice, ['2026-11-14-N'], 'exclusive');
    $autre = reserverPour($this->bruno, ['2026-11-15-N']);
    $creneau = DB::table('creneaux')->where('date', '2026-11-14')->where('type', 'N')->value('id');
    DB::table('reservation_creneau')->insert(['reservation_id' => $autre->id, 'creneau_id' => $creneau]);
    $avant = DB::table('reservation_creneau')->orderBy('reservation_id')->orderBy('creneau_id')->get()->toArray();

    $this->artisan('creneaux:verifier');

    expect(DB::table('reservation_creneau')->orderBy('reservation_id')->orderBy('creneau_id')->get()->toArray())->toEqual($avant);
});

it('détecte aussi une réservation sur des vacances, un doublon de membre et une fermeture', function () {
    $r = reserverPour($this->alice, ['2026-11-10-N']);
    mettreVacances('2026-11-10', '2026-11-10');
    $r2 = reserverPour($this->alice, ['2026-11-12-N']);
    $creneau = DB::table('creneaux')->where('date', '2026-11-12')->where('type', 'N')->value('id');
    $r3 = reserverPour($this->alice, ['2026-11-13-N']);
    DB::table('reservation_creneau')->insert(['reservation_id' => $r3->id, 'creneau_id' => $creneau]);
    reserverPour($this->bruno, ['2026-11-25-N']);
    DB::table('fermetures')->insert(['debut' => '2026-11-25', 'fin' => '2026-11-25', 'motif' => 'Travaux', 'saisie_par' => $this->admin->id]);

    $anomalies = app(App\Domain\Calendrier\ControleCoherence::class)->anomalies();

    expect(implode("\n", $anomalies))->toContain('créneau de vacances')->toContain('plusieurs réservations')->toContain('fermeture');
});

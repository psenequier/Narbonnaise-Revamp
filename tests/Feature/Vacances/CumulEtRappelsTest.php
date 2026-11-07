<?php

use App\Mail\MessageSite;
use App\Models\Lignee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(fn () => Mail::fake());

it('n\'a plus d\'écran de cumul par lignée (retiré le 7 nov. 2026)', function () {
    $admin = adminAvec2fa();
    Lignee::create(['nom' => 'Lignée Bleue', 'couleur' => '#2F6FB0']);

    $this->actingAs($admin)->get('/admin/lignees')->assertNotFound();
    $this->actingAs($admin)->get('/admin/vacances')->assertOk()->assertDontSee('aria-labelledby="cumul"', false)->assertDontSee('Cumul par lignée');
});

it('US-406-P1 relance chacun des 3 admins le 1er septembre, le 1er octobre et le 15 octobre', function (string $jour) {
    $this->travelTo('2026-08-20 10:00:00');
    ouvrirAnnee2026();
    $admins = collect(range(1, 3))->map(fn ($i) => User::factory()->admin()->create(['email' => "admin{$i}@example.test"]));
    Mail::fake();

    $this->travelTo(new DateTimeImmutable("{$jour} 08:00", new DateTimeZone('Europe/Paris')));
    $this->artisan('attributions:rappeler')->assertSuccessful();
    $this->artisan('attributions:rappeler')->assertSuccessful(); // une seule relance par jour

    $rappels = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'admin.rappel_attributions');
    expect($rappels)->toHaveCount(3);
    foreach ($admins as $a) {
        expect($rappels->filter(fn ($m) => $m->hasTo($a->email)))->toHaveCount(1);
    }
})->with(['2026-09-01', '2026-10-01', '2026-10-15']);

it('US-406-P2 affiche le bandeau admin tant qu\'il reste un créneau sans lignée', function () {
    $this->travelTo('2026-11-02 10:00:00');
    ouvrirAnnee2026();

    $this->actingAs(adminAvec2fa())->get('/')->assertOk()->assertSee('data-bandeau="attributions"', false)->assertSee('64 créneau(x)');
});

it('US-406-N1 n\'envoie aucun rappel et retire le bandeau quand tout est attribué', function () {
    $this->travelTo('2026-08-20 10:00:00');
    ouvrirAnnee2026();
    $admin = adminAvec2fa();
    $lignee = Lignee::create(['nom' => 'Lignée Bleue', 'couleur' => '#2F6FB0']);
    foreach (DB::table('creneaux')->whereNotNull('periode_id')->pluck('id') as $id) {
        DB::table('attributions')->insert(['creneau_id' => $id, 'lignee_id' => $lignee->id, 'saisie_par' => $admin->id]);
    }
    Mail::fake();

    $this->travelTo(new DateTimeImmutable('2026-10-01 08:00', new DateTimeZone('Europe/Paris')));
    $this->artisan('attributions:rappeler')->assertSuccessful();

    Mail::assertNothingSent();
    $this->actingAs($admin)->get('/')->assertDontSee('data-bandeau="attributions"', false);
});

it('US-406-N2 n\'envoie aucun rappel les autres jours', function (string $jour) {
    $this->travelTo('2026-08-20 10:00:00');
    ouvrirAnnee2026();
    User::factory()->admin()->create();
    Mail::fake();

    $this->travelTo(new DateTimeImmutable("{$jour} 08:00", new DateTimeZone('Europe/Paris')));
    $this->artisan('attributions:rappeler')->assertSuccessful();

    Mail::assertNothingSent();
})->with(['2026-10-02', '2026-09-15', '2026-11-01']);

it('US-406-N3 ne relance jamais les membres non admins', function () {
    $this->travelTo('2026-08-20 10:00:00');
    ouvrirAnnee2026();
    User::factory()->admin()->create(['email' => 'admin@example.test']);
    User::factory()->create(['email' => 'membre@example.test']);
    Mail::fake();

    $this->travelTo(new DateTimeImmutable('2026-10-15 08:00', new DateTimeZone('Europe/Paris')));
    $this->artisan('attributions:rappeler');

    Mail::assertSent(MessageSite::class, fn ($m) => $m->gabarit === 'admin.rappel_attributions' && $m->hasTo('admin@example.test'));
    Mail::assertNotSent(MessageSite::class, fn ($m) => $m->hasTo('membre@example.test') || $m->hasBcc('membre@example.test'));
    $this->actingAs(User::where('email', 'membre@example.test')->sole())->get('/')->assertDontSee('data-bandeau="attributions"', false);
});

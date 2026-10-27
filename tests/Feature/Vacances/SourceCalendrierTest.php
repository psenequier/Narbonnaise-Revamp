<?php

use App\Domain\Vacances\PeriodesScolaires;
use App\Mail\MessageSite;
use App\Models\PeriodeVacances;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-01 03:00:00');
    $this->admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
});

function alertesSource(): \Illuminate\Support\Collection
{
    return Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'admin.source_vacances');
}

it('US-401-P1 calcule Noël et février 2026-2027 à partir de la réponse officielle', function () {
    simulerCalendrier();

    app(PeriodesScolaires::class)->synchroniserAnnee('2026-2027');

    expect(periode('noel')->premiere_nuit->format('Y-m-d'))->toBe('2026-12-18')
        ->and(periode('noel')->dernier_jour->format('Y-m-d'))->toBe('2027-01-03')
        ->and(periode('hiver')->premiere_nuit->format('Y-m-d'))->toBe('2027-02-12')
        ->and(periode('hiver')->dernier_jour->format('Y-m-d'))->toBe('2027-02-28');
});

it('US-401-P2 lance la tâche le 1er du mois, et pas les autres jours', function () {
    $prevue = fn () => collect(app(Schedule::class)->events())
        ->filter(fn ($e) => str_contains((string) $e->command, 'vacances:verifier'))
        ->contains(fn ($e) => $e->isDue(app()));

    $this->travelTo(new DateTimeImmutable('2026-12-01 04:00', new DateTimeZone('Europe/Paris')));
    expect($prevue())->toBeTrue();
    $this->travelTo(new DateTimeImmutable('2026-12-02 04:00', new DateTimeZone('Europe/Paris')));
    expect($prevue())->toBeFalse();
});

it('US-401-P3 prévient les admins avec les dates avant et après quand la source change', function () {
    ouvrirAnnee2026();
    Mail::fake();
    simulerCalendrier(noel: ['2026-12-11T23:00:00+00:00', '2027-01-03T23:00:00+00:00']);

    $this->artisan('vacances:verifier')->assertSuccessful();

    $alerte = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'admin.vacances_modifiees')->sole();
    expect($alerte->hasTo('admin@example.test'))->toBeTrue()
        ->and($alerte->render())->toContain('avant du 18/12/2026')->toContain('après du 11/12/2026')
        ->and(periode('noel')->premiere_nuit->format('Y-m-d'))->toBe('2026-12-11');
});

it('US-401-N1 ne modifie rien et alerte les admins quand la source répond mal', function (Closure $reponse, string $motif) {
    ouvrirAnnee2026();
    Mail::fake();
    $avant = PeriodeVacances::orderBy('id')->get(['premiere_nuit', 'dernier_jour'])->toArray();
    $creneaux = DB::table('creneaux')->whereNotNull('periode_id')->count();
    reinitialiserHttp();
    Http::fake(['*' => $reponse]);

    $this->artisan('vacances:verifier')->assertFailed();

    expect(PeriodeVacances::orderBy('id')->get(['premiere_nuit', 'dernier_jour'])->toArray())->toBe($avant)
        ->and(DB::table('creneaux')->whereNotNull('periode_id')->count())->toBe($creneaux)
        ->and(alertesSource())->toHaveCount(1)
        ->and(alertesSource()->first()->render())->toContain($motif);
})->with([
    'source indisponible ou délai dépassé' => [fn () => fn () => throw new ConnectionException('Operation timed out after 10000 ms'), 'délai dépassé'],
    'code HTTP 500' => [fn () => fn () => Http::response('Erreur', 500), 'code HTTP 500'],
    'JSON invalide' => [fn () => fn () => Http::response('<html>pas du JSON</html>', 200), 'JSON invalide'],
    'schéma inattendu' => [fn () => fn () => Http::response(['records' => []], 200), 'Schéma inattendu'],
    'dates incohérentes' => [fn () => fn () => Http::response(['results' => [
        enregistrementScolaire('Vacances de Noël', 'Zone A', '2027-01-03T23:00:00+00:00', '2026-12-18T23:00:00+00:00'),
    ]], 200), 'incohérentes'],
]);

it('US-401-N2 ignore les zones B et C', function () {
    simulerCalendrier(hiver: null); // seule la zone A compte : l'hiver des zones B et C ne suffit pas

    app(PeriodesScolaires::class)->synchroniserAnnee('2026-2027');

    expect(PeriodeVacances::where('nom', 'hiver')->exists())->toBeFalse()
        ->and(periode('noel')->premiere_nuit->format('Y-m-d'))->toBe('2026-12-18');
});

it('US-401-N3 n\'appelle jamais une URL en HTTP ou qui pointe vers une adresse privée', function (string $url) {
    app()->instance(\App\Domain\Sources\UrlSourceSure::class, new \App\Domain\Sources\UrlSourceSure(fn () => ['10.0.0.8']));
    DB::table('parametres')->insert(['cle' => 'source.calendrier_scolaire', 'valeur' => $url, 'created_at' => now(), 'updated_at' => now()]);
    app(\App\Domain\Parametres::class)->oublier();
    Http::fake();

    $this->artisan('vacances:verifier')->assertFailed();

    Http::assertNothingSent();
    expect(alertesSource())->toHaveCount(1)->and(PeriodeVacances::count())->toBe(0);
})->with(['http://data.education.gouv.fr/api', 'https://intranet.example.test/api', 'https://127.0.0.1/api']);

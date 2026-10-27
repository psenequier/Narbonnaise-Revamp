<?php

use App\Domain\Calendrier\EtatCreneau;
use App\Domain\Calendrier\Occupation;
use App\Domain\Vacances\PeriodesScolaires;
use App\Mail\MessageSite;
use App\Models\AnneeScolaire;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-01 03:00:00');
    $this->admin = User::factory()->admin()->create(['prenom' => 'Ada', 'nom' => 'Admin', 'email' => 'admin@example.test']);
    $this->membre = User::factory()->create(['email' => 'membre@example.test']);
});

it('US-402-P1 crée les créneaux du 1er septembre au 31 août, vacances en attente d\'attribution', function () {
    $annee = ouvrirAnnee2026();

    expect($annee->ouverte)->toBeTrue()
        ->and(DB::table('creneaux')->count())->toBe(730)
        ->and(DB::table('creneaux')->min('date'))->toBe('2026-09-01')
        ->and(DB::table('creneaux')->max('date'))->toBe('2027-08-31')
        ->and(DB::table('creneaux')->whereNotNull('periode_id')->count())->toBe(64);

    $noel = app(Occupation::class)->entre(new DateTimeImmutable('2026-12-19'), new DateTimeImmutable('2027-01-02'));
    expect(collect($noel)->every(fn ($s) => $s->etat === EtatCreneau::Attente))->toBeTrue();
});

it('US-402-P2 annonce l\'ouverture aux admins puis aux membres', function () {
    ouvrirAnnee2026();

    $annonces = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'vacances.ouverture')->values();
    expect($annonces)->toHaveCount(2)
        ->and($annonces[0]->hasTo('admin@example.test'))->toBeTrue()
        ->and($annonces[1]->hasBcc('membre@example.test'))->toBeTrue()
        ->and($annonces[1]->hasBcc('admin@example.test'))->toBeFalse()
        ->and($annonces[0]->render())->toContain('2026-2027')->toContain('18/12/2026');
});

it('US-504-P2 déclenche une annonce à l\'ouverture d\'une année', function () {
    ouvrirAnnee2026();

    Mail::assertSent(MessageSite::class, fn ($m) => $m->gabarit === 'vacances.ouverture' && $m->hasBcc('membre@example.test'));
});

it('US-402-P3 ne crée aucun doublon quand on relance l\'ouverture', function () {
    $annee = ouvrirAnnee2026();
    Mail::fake();

    app(PeriodesScolaires::class)->synchroniserAnnee('2026-2027');
    $relance = app(PeriodesScolaires::class)->ouvrirSiPossible($annee->refresh());

    expect($relance)->toBeFalse()
        ->and(DB::table('creneaux')->count())->toBe(730)
        ->and(DB::table('periodes_vacances')->count())->toBe(2);
    Mail::assertNothingSent();
});

it('US-402-N1 laisse l\'année fermée, sans créneau, si seul Noël est connu', function () {
    simulerCalendrier(hiver: null);

    app(PeriodesScolaires::class)->synchroniserAnnee('2026-2027');

    expect(AnneeScolaire::where('libelle', '2026-2027')->sole()->ouverte)->toBeFalse()
        ->and(DB::table('creneaux')->count())->toBe(0);
    Mail::assertNothingSent();
});

it('US-402-N2 ne crée aucun créneau de vacances à l\'état « libre »', function () {
    ouvrirAnnee2026();

    $situations = app(Occupation::class)->entre(new DateTimeImmutable('2026-12-18'), new DateTimeImmutable('2027-03-01'));
    $vacances = DB::table('creneaux')->whereNotNull('periode_id')->get(['date', 'type'])
        ->map(fn ($c) => $c->date.'-'.$c->type);

    expect($vacances)->toHaveCount(64);
    foreach ($vacances as $cle) {
        expect($situations[$cle]->etat)->not->toBe(EtatCreneau::Libre);
    }
});

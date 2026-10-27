<?php

use App\Domain\Reprise\ControleReprise;
use App\Domain\Reprise\ImportGrr;
use App\Models\Lignee;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

const ANCIEN_MDP = 'Ancien-Mot-2019!';

beforeEach(function () {
    $this->travelTo('2026-11-20 10:00:00');
    creerBaseGrr();
    $this->bleue = Lignee::create(['nom' => 'Lignée Bleue', 'couleur' => '#2F6FB0']);
});

/** La base fictive « propre » : trois comptes sans anomalie. */
function comptesPropres(): void
{
    grrCompte('alice', 'alice@example.test', password_hash(ANCIEN_MDP, PASSWORD_BCRYPT), prenom: 'Alice', nom: 'Martin');
    grrCompte('bob', 'Bob@Example.test', md5('secret'), 'administrateur', prenom: 'Bob', nom: 'Durand');
    grrCompte('denis', 'denis@example.test', md5('x'), etat: 'inactif', prenom: 'Denis', nom: 'Roux');
}

/** Tous les cas de la reprise : répétitions, chevauchements, attributions, entrées corrompues, doublons. */
function baseComplete(): array
{
    comptesPropres();
    grrCompte('carole', 'carole@example.test');
    grrCompte('carole2', 'CAROLE@example.test');
    grrCompte('sansmail', '');

    return [
        'weekend' => grrEntree('alice', '2025-11-15 14:00', '2025-11-16 18:00'),
        'repetition' => [
            grrEntree('bob', '2025-01-11 09:00', '2025-01-11 18:00', 'A', repetition: 7),
            grrEntree('bob', '2025-01-18 09:00', '2025-01-18 18:00', 'A', repetition: 7),
            grrEntree('bob', '2025-01-25 09:00', '2025-01-25 18:00', 'A', repetition: 7),
        ],
        'exclusive' => grrEntree('alice', '2025-03-08 12:00', '2025-03-09 12:00', 'E'),
        'chevauche' => grrEntree('bob', '2025-03-08 14:00', '2025-03-08 16:00', 'A'),
        'attribution' => grrEntree('bob', '2024-12-20 18:00', '2024-12-28 12:00', 'A', 'Noël Lignée Bleue'),
        'date_invalide' => grrEntree('alice', '0', '2025-04-01 12:00'),
        'fantome' => grrEntree('fantome', '2025-04-05 12:00', '2025-04-06 12:00'),
        'doublon' => grrEntree('carole2', '2025-04-12 12:00', '2025-04-13 12:00'),
        'sans_creneau' => grrEntree('alice', '2025-02-01 18:00', '2025-02-01 19:00'),
        'avenir' => grrEntree('alice', '2026-12-05 12:00', '2026-12-06 12:00', 'A', '', 'Avec les cousins'),
    ];
}

function creneauxDe(int $grrId): array
{
    return DB::table('reservation_creneau as rc')->join('reservations as r', 'r.id', '=', 'rc.reservation_id')
        ->join('creneaux as c', 'c.id', '=', 'rc.creneau_id')->where('r.grr_id', $grrId)
        ->orderBy('c.date')->orderBy('c.type')->get(['c.date', 'c.type'])->map(fn ($c) => $c->date.'-'.$c->type)->all();
}

it('US-801-P1 reprend chaque compte avec nom, prénom, e-mail et rôle, en même nombre', function () {
    comptesPropres();

    app(ImportGrr::class)->importer();

    expect(User::whereNotNull('grr_login')->count())->toBe(DB::table('grr_utilisateurs')->count());
    $bob = User::where('grr_login', 'bob')->sole();
    expect($bob->only(['prenom', 'nom', 'email', 'role']))->toBe(['prenom' => 'Bob', 'nom' => 'Durand', 'email' => 'bob@example.test', 'role' => 'admin'])
        ->and(User::where('grr_login', 'alice')->sole()->role)->toBe('membre')
        ->and(User::where('grr_login', 'denis')->sole()->actif)->toBeFalse()
        ->and(app(ControleReprise::class)->rapport()['comptes']['ecart'])->toBeFalse();
});

it('US-801-P2 connecte un compte bcrypt avec son ancien mot de passe, puis le rehache', function () {
    comptesPropres();
    app(ImportGrr::class)->importer();

    $this->post('/login', ['email' => 'alice@example.test', 'password' => ANCIEN_MDP])->assertRedirect('/');

    $this->assertAuthenticatedAs(User::where('grr_login', 'alice')->sole());
    expect(password_get_info(User::where('grr_login', 'alice')->sole()->password)['algoName'])->toBe(Hash::info(Hash::make('x'))['algoName']);
});

it('US-801-N1 refuse l\'ancien mot de passe MD5 et marque le compte « à réinitialiser »', function () {
    comptesPropres();
    app(ImportGrr::class)->importer();

    $this->post('/login', ['email' => 'bob@example.test', 'password' => 'secret'])->assertSessionHasErrors('email');

    $this->assertGuest();
    $bob = User::where('grr_login', 'bob')->sole();
    expect($bob->a_reinitialiser)->toBeTrue()->and($bob->statut())->toBe('À réinitialiser');
});

it('US-801-N2 ne fusionne pas deux comptes GRR à la même adresse et les signale', function () {
    baseComplete();

    $rapport = app(ImportGrr::class)->importer();

    expect(User::where('email', 'carole@example.test')->exists())->toBeFalse()
        ->and(implode("\n", $rapport['doublons_email']))->toContain('carole')->toContain('carole2');
});

it('US-801-N3 ne conserve aucune empreinte MD5', function () {
    baseComplete();

    app(ImportGrr::class)->importer();

    $empreintes = User::whereNotNull('grr_login')->pluck('password')->filter();
    foreach ($empreintes as $e) {
        expect($e)->not->toMatch('/^[a-f0-9]{32}$/i');
    }
    expect(json_encode(DB::table('users')->get()))->not->toContain(md5('secret'))->not->toContain(md5('x'));
});

it('US-802-P1 transforme samedi 14 h → dimanche 18 h en samedi J et N, dimanche J', function () {
    $e = baseComplete();

    app(ImportGrr::class)->importer();

    expect(creneauxDe($e['weekend']))->toBe(['2025-11-15-J', '2025-11-15-N', '2025-11-16-J']);
});

it('US-802-P2 fait d\'une répétition de 3 occurrences 3 réservations distinctes', function () {
    $e = baseComplete();

    app(ImportGrr::class)->importer();

    expect(Reservation::whereIn('grr_id', $e['repetition'])->count())->toBe(3)
        ->and(Reservation::whereIn('grr_id', $e['repetition'])->pluck('id')->unique())->toHaveCount(3);
});

it('US-802-P3 rend « ouverte » une réservation sans type et propose les anciennes attributions à la validation', function () {
    $e = baseComplete();
    app(ImportGrr::class)->importer();

    expect(Reservation::where('grr_id', $e['weekend'])->sole()->mode)->toBe('ouverte')
        ->and(Reservation::where('grr_id', $e['exclusive'])->sole()->mode)->toBe('exclusive')
        ->and(Reservation::where('grr_id', $e['attribution'])->exists())->toBeFalse();
    $p = DB::table('reprise_propositions')->where('grr_entry_id', $e['attribution'])->sole();
    expect($p->lignee_id)->toBe($this->bleue->id)->and($p->statut)->toBe('en_attente');

    $admin = adminAvec2fa();
    $this->actingAs($admin)->get('/admin/reprise')->assertOk()->assertSee('data-proposition="'.$p->id.'"', false);
    $this->actingAs($admin)->post("/admin/reprise/propositions/{$p->id}")->assertRedirect();

    expect(DB::table('attributions')->where('lignee_id', $this->bleue->id)->count())->toBe(count(json_decode($p->creneaux)))
        ->and(DB::table('reprise_propositions')->where('id', $p->id)->value('statut'))->toBe('validee');
    $this->actingAs(User::factory()->create())->post("/admin/reprise/propositions/{$p->id}")->assertForbidden();
});

it('US-802-P4 donne exactement le même résultat quand l\'import est lancé deux fois', function () {
    baseComplete();
    $photo = fn () => [
        DB::table('users')->whereNotNull('grr_login')->orderBy('grr_login')->get(['grr_login', 'email', 'role', 'actif', 'password'])->toArray(),
        DB::table('reservations')->orderBy('grr_id')->get(['grr_id', 'user_id', 'mode', 'commentaire'])->toArray(),
        DB::table('reservation_creneau')->orderBy('reservation_id')->orderBy('creneau_id')->get()->toArray(),
        DB::table('creneaux')->count(),
        DB::table('reprise_propositions')->orderBy('grr_entry_id')->get(['grr_entry_id', 'lignee_id', 'creneaux'])->toArray(),
    ];

    app(ImportGrr::class)->importer();
    $premier = $photo();
    app(ImportGrr::class)->importer();

    expect($photo())->toEqual($premier);
});

it('US-802-N1 reprend les chevauchements historiques tels quels et les liste', function () {
    $e = baseComplete();

    $rapport = app(ImportGrr::class)->importer();

    expect(Reservation::whereIn('grr_id', [$e['exclusive'], $e['chevauche']])->whereNull('annulee_le')->count())->toBe(2)
        ->and(implode("\n", $rapport['chevauchements']))->toContain('2025-03-08 J')->toContain((string) $e['chevauche']);
});

it('US-802-N2 rejette et liste les entrées corrompues sans interrompre l\'import', function () {
    $e = baseComplete();

    $rapport = app(ImportGrr::class)->importer();
    $rejets = implode("\n", $rapport['rejets']);

    foreach (['date_invalide', 'fantome', 'doublon', 'sans_creneau'] as $cas) {
        expect($rejets)->toContain('n° '.$e[$cas].' ');
        expect(Reservation::where('grr_id', $e[$cas])->exists())->toBeFalse();
    }
    expect(Reservation::where('grr_id', $e['avenir'])->exists())->toBeTrue()
        ->and($rejets)->toContain('dates invalides')->toContain('fantome');
});

it('US-802-N3 ne modifie aucune table GRR', function () {
    baseComplete();
    $avant = sommeGrr();

    app(ImportGrr::class)->importer();
    app(ImportGrr::class)->importer();

    expect(sommeGrr())->toBe($avant);
});

it('US-803-P1 affiche par année des totaux égaux de part et d\'autre, et 20 réservations tirées au hasard', function () {
    baseComplete();
    foreach (range(1, 25) as $i) {
        $jour = (new DateTimeImmutable('2024-03-01'))->modify("+{$i} days")->format('Y-m-d');
        grrEntree('alice', "{$jour} 12:00", "{$jour} 16:00", 'A');
    }

    $this->artisan('grr:importer')->assertSuccessful();
    $rapport = app(ControleReprise::class)->rapport();

    expect($rapport['valide'])->toBeTrue()
        ->and(array_keys($rapport['annees']))->toBe(['2024', '2025', '2026'])
        ->and($rapport['annees']['2024']['grr_reservations'])->toBe(25)
        ->and($rapport['annees']['2025']['grr_nuits'])->toBe($rapport['annees']['2025']['site_nuits'])
        ->and($rapport['echantillon'])->toHaveCount(20);
    foreach ($rapport['annees'] as $a) {
        expect($a['grr_reservations'])->toBe($a['site_reservations'])->and($a['grr_nuits'])->toBe($a['site_nuits']);
    }
    $this->actingAs(adminAvec2fa())->get('/admin/reprise')->assertOk()->assertSee('data-controle="valide"', false);
});

it('US-803-N1 signale l\'écart sur la bonne année quand une réservation a disparu, et passe « non validé »', function () {
    $e = baseComplete();
    app(ImportGrr::class)->importer();

    Reservation::where('grr_id', $e['weekend'])->sole()->delete();
    $rapport = app(ControleReprise::class)->rapport();

    expect($rapport['valide'])->toBeFalse()
        ->and($rapport['annees']['2025']['ecart'])->toBeTrue()
        ->and($rapport['annees']['2026']['ecart'])->toBeFalse();
    $this->artisan('grr:importer --controle-seul')->assertFailed();
});

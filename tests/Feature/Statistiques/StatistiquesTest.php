<?php

use App\Domain\Statistiques\Statistiques;
use App\Models\Lignee;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2027-04-20 10:00:00');
    $this->membre = User::factory()->create(['prenom' => 'Zoé', 'nom' => 'Témoin', 'email' => 'zoe@example.test']);
    $this->admin = User::factory()->admin()->create();
    $this->lignee = Lignee::create(['nom' => 'Lignée fictive', 'couleur' => '#23302A']);
});

/** Occupe des créneaux (nuits, et journées si demandé) : une réservation par appel. */
function occuper(User $membre, string $du, int $nuits, string $mode = 'ouverte', int $personnes = 4, bool $journees = false, bool $grr = false, bool $annulee = false): void
{
    static $grrId = 1000;
    $id = DB::table('reservations')->insertGetId([
        'user_id' => $membre->id, 'mode' => $mode, 'nombre_personnes' => $personnes,
        'grr_id' => $grr ? $grrId++ : null, 'annulee_le' => $annulee ? now() : null, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $jour = new DateTimeImmutable($du);
    for ($i = 0; $i < $nuits; $i++, $jour = $jour->modify('+1 day')) {
        foreach ($journees ? ['N', 'J'] : ['N'] as $type) {
            DB::table('creneaux')->insertOrIgnore(['date' => $jour->format('Y-m-d'), 'type' => $type, 'created_at' => now(), 'updated_at' => now()]);
            $creneau = DB::table('creneaux')->where('date', $jour->format('Y-m-d'))->where('type', $type)->value('id');
            DB::table('reservation_creneau')->insert(['reservation_id' => $id, 'creneau_id' => $creneau]);
        }
    }
}

function attribuerNuits(Lignee $lignee, User $admin, string $du, int $nuits): void
{
    $jour = new DateTimeImmutable($du);
    for ($i = 0; $i < $nuits; $i++, $jour = $jour->modify('+1 day')) {
        DB::table('creneaux')->insertOrIgnore(['date' => $jour->format('Y-m-d'), 'type' => 'N', 'created_at' => now(), 'updated_at' => now()]);
        $creneau = DB::table('creneaux')->where('date', $jour->format('Y-m-d'))->where('type', 'N')->value('id');
        DB::table('attributions')->insert(['creneau_id' => $creneau, 'lignee_id' => $lignee->id, 'saisie_par' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
    }
}

function stats(): Statistiques
{
    return app(Statistiques::class);
}

it('US-701-P1 affiche 61 % et « 79 nuits sur 129 » pour 79 nuits occupées sur une saison de 129', function () {
    // Saison de 129 nuits : ouverture le 1er déc. 2026, fermeture le 9 avr. 2027 (dernière nuit le 8 avril).
    DB::table('parametres')->insert([
        ['cle' => 'station.ouverture.2026-2027', 'valeur' => '2026-12-01', 'created_at' => now(), 'updated_at' => now()],
        ['cle' => 'station.fermeture.2026-2027', 'valeur' => '2027-04-09', 'created_at' => now(), 'updated_at' => now()],
    ]);
    cache()->forget('parametres');
    occuper($this->membre, '2026-12-05', 40);
    attribuerNuits($this->lignee, $this->admin, '2027-02-12', 16);
    occuper($this->membre, '2027-03-01', 23, 'exclusive');

    $this->actingAs($this->membre)->get('/statistiques?saison=2026-2027')->assertOk()
        ->assertSeeInOrder(['Nuits occupées', '61 %', '79 nuits sur 129']);
});

it('US-701-P2 calcule nuitées-personnes, journées et samedis soirs, en saison comme en année civile', function () {
    occuper($this->membre, '2026-12-18', 3, personnes: 5, journees: true);  // ven., sam., dim. ; 1 samedi soir
    occuper($this->membre, '2027-01-08', 2, personnes: 2);                  // ven., sam. ; 1 samedi soir

    $saison = stats()->bornesSaison('2026-2027');
    $p = stats()->periode($saison['debut'], $saison['fin']);
    expect($p['nuits'])->toBe(5)->and($p['journees'])->toBe(3)->and($p['nuitees_personnes'])->toBe(19)
        ->and($p['samedis_occupes'])->toBe(2);

    $annee = Statistiques::bornesAnnee(2026);
    $p = stats()->periode($annee['debut'], $annee['fin']);
    expect($p['jours'])->toBe(365)->and($p['nuits'])->toBe(3)->and($p['nuitees_personnes'])->toBe(15)
        ->and($p['samedis'])->toBe(52)->and($p['samedis_occupes'])->toBe(1);
});

it('US-701-P3 compte la saison de l\'ouverture à la veille de la fermeture, ou du 1er décembre au 30 avril', function () {
    $saisie = stats()->bornesSaison('2026-2027'); // dates de la station : 5 déc. 2026 et 11 avr. 2027
    expect($saisie['debut']->format('Y-m-d'))->toBe('2026-12-05')->and($saisie['fin']->format('Y-m-d'))->toBe('2027-04-10')
        ->and($saisie['approchee'])->toBeFalse();

    $approchee = stats()->bornesSaison('2024-2025');
    expect($approchee['debut']->format('Y-m-d'))->toBe('2024-12-01')->and($approchee['fin']->format('Y-m-d'))->toBe('2025-04-29')
        ->and($approchee['approchee'])->toBeTrue();
    occuper($this->membre, '2025-01-10', 2, grr: true);
    $this->actingAs($this->membre)->get('/statistiques?saison=2024-2025')->assertOk()->assertSee('(dates approchées)');
});

it('US-701-N1 le module de statistiques ne lit que la vue v_occupation', function () {
    $source = file_get_contents(app_path('Domain/Statistiques/Statistiques.php')).file_get_contents(app_path('Http/Controllers/StatistiquesController.php'));
    preg_match_all("/DB::table\\('([a-z_]+)'\\)/", $source, $tables);

    expect(array_unique($tables[1]))->toBe(['v_occupation'])
        ->and($source)->not->toContain('App\\Models\\User')->not->toContain('App\\Models\\Reservation')->not->toContain('App\\Models\\Lignee');
});

arch('US-701-N1 aucune dépendance vers les modèles de membres, réservations ou lignées')
    ->expect('App\Domain\Statistiques')
    ->not->toUse(['App\Models\User', 'App\Models\Reservation', 'App\Models\Lignee', 'App\Models\Attribution']);

it('US-701-N2 ne montre aucun nom, e-mail, identifiant de membre ni nom de lignée', function () {
    occuper($this->membre, '2026-12-18', 3);
    attribuerNuits($this->lignee, $this->admin, '2027-02-12', 4);

    $page = $this->actingAs($this->admin)->get('/statistiques?saison=2026-2027')->assertOk()->getContent();
    expect($page)->not->toContain('Témoin')->not->toContain('zoe@example.test')->not->toContain('Lignée fictive')
        ->and(substr($page, (int) strpos($page, '<main')))->not->toContain('Zoé');
});

it('US-701-N3 ignore les paramètres user, membre et lignee', function () {
    occuper($this->membre, '2026-12-18', 3);
    $normal = $this->actingAs($this->membre)->get('/statistiques?saison=2026-2027')->getContent();
    $filtre = $this->actingAs($this->membre)->get('/statistiques?saison=2026-2027&user='.$this->admin->id.'&membre=1&lignee='.$this->lignee->id)->getContent();

    expect(preg_replace('/name="_token" value="[^"]*"/', '', $filtre))->toBe(preg_replace('/name="_token" value="[^"]*"/', '', $normal));
});

it('US-701-N4 compte libre un créneau de vacances non attribué, ignore une réservation annulée, et affiche 0 % sans données', function () {
    $this->actingAs($this->membre)->get('/statistiques?vue=annee&annee=2027')->assertOk()->assertSee('0 %')->assertSee('0 nuits sur 365');

    occuper($this->membre, '2026-12-20', 3, annulee: true);
    DB::table('creneaux')->insertOrIgnore(['date' => '2027-02-14', 'type' => 'N', 'created_at' => now(), 'updated_at' => now()]); // vacances sans lignée
    $saison = stats()->bornesSaison('2026-2027');
    expect(stats()->periode($saison['debut'], $saison['fin'])['nuits'])->toBe(0);
});

it('US-702-P1 répartit les nuits de chaque mois par type, et le total égale les nuits occupées', function () {
    occuper($this->membre, '2026-12-10', 4);
    occuper($this->membre, '2026-12-20', 2, 'exclusive');
    attribuerNuits($this->lignee, $this->admin, '2027-02-13', 5);

    $saison = stats()->bornesSaison('2026-2027');
    $mois = collect(stats()->periode($saison['debut'], $saison['fin'])['mois'])->keyBy('mois');
    expect($mois->keys()->all())->toBe(['2026-12', '2027-01', '2027-02', '2027-03', '2027-04'])
        ->and($mois['2026-12'])->toMatchArray(['ouverte' => 4, 'exclusive' => 2, 'attribuee' => 0, 'total' => 6])
        ->and($mois['2027-02'])->toMatchArray(['attribuee' => 5, 'total' => 5]);

    $annee = Statistiques::bornesAnnee(2027);
    expect(stats()->periode($annee['debut'], $annee['fin'])['mois'])->toHaveCount(12);
});

it('US-702-P3 colore les types comme le calendrier et écrit leur total dans la légende', function () {
    occuper($this->membre, '2026-12-10', 4);
    occuper($this->membre, '2026-12-20', 2, 'exclusive');

    $this->actingAs($this->membre)->get('/statistiques?saison=2026-2027')->assertOk()
        ->assertSee('fill="#A8D3E6"', false)->assertSee('fill="#F2D06B"', false)
        ->assertSeeInOrder(['Ouvertes · 4 nuits', 'Exclusives · 2 nuits', 'Attribuées · 0 nuits']);
});

it('US-702-P4 montre les nuits non occupées du mois : 20 / 27 en décembre d\'une saison ouverte le 5', function () {
    occuper($this->membre, '2026-12-05', 20);

    $saison = stats()->bornesSaison('2026-2027');
    $decembre = stats()->periode($saison['debut'], $saison['fin'])['mois'][0];
    expect($decembre)->toMatchArray(['mois' => '2026-12', 'total' => 20, 'jours' => 27, 'libres' => 7]);
    $this->actingAs($this->membre)->get('/statistiques?saison=2026-2027')
        ->assertSee('<tspan font-weight="800">20</tspan><tspan fill="#56664F"> / 27</tspan>', false)
        ->assertSee('Non occupées · 107 nuits');
});

it('US-702-N1 compte une nuit une seule fois, quelle que soit la réservation qui l\'occupe', function () {
    occuper($this->membre, '2026-12-10', 2);
    occuper($this->admin, '2026-12-10', 2); // ancien site : deux réservations sur la même nuit

    $saison = stats()->bornesSaison('2026-2027');
    expect(stats()->periode($saison['debut'], $saison['fin'])['nuits'])->toBe(2);
});

it('US-703-P1 liste chaque saison depuis la première reprise, avec son taux et sa source', function () {
    occuper($this->membre, '2024-12-20', 10, grr: true);
    occuper($this->membre, '2025-12-20', 5, grr: true);
    occuper($this->membre, '2026-12-20', 5);

    $saisons = collect(stats()->saisons(new DateTimeImmutable('2027-04-20')));
    expect($saisons->pluck('saison')->all())->toBe(['2024-2025', '2025-2026', '2026-2027'])
        ->and($saisons->pluck('source')->all())->toBe(['ancien site', 'ancien site', 'nouveau site'])
        ->and($saisons[0]['taux'])->toBe(Statistiques::taux(10, 150));

    occuper($this->membre, '2027-01-10', 2, grr: true);
    expect(collect(stats()->saisons(new DateTimeImmutable('2027-04-20')))->last()['source'])->toBe('ancien et nouveau site');
});

it('US-703-N1 affiche une saison sans dates saisies du 1er décembre au 30 avril, « dates approchées »', function () {
    occuper($this->membre, '2023-12-24', 4, grr: true);

    $saison = collect(stats()->saisons(new DateTimeImmutable('2027-04-20')))->firstWhere('saison', '2023-2024');
    expect($saison['approchee'])->toBeTrue()->and($saison['jours'])->toBe(151);
    $this->actingAs($this->membre)->get('/statistiques')->assertOk()->assertSee('ancien site · dates approchées');
});

it('US-703-N2 n\'affiche aucune saison antérieure à la première réservation reprise', function () {
    occuper($this->membre, '2025-12-20', 3, grr: true);

    expect(collect(stats()->saisons(new DateTimeImmutable('2027-04-20')))->pluck('saison')->all())->toBe(['2025-2026', '2026-2027']);
});

it('ouvre les statistiques depuis le menu', function () {
    $this->actingAs($this->membre)->get('/')->assertSee('href="'.route('statistiques').'"', false)->assertSee('href="'.route('meteo').'"', false);
});

it('US-703-P2 « Depuis le début » compte de la première occupation à aujourd\'hui, une barre par année', function () {
    occuper($this->membre, '2024-12-20', 3, grr: true);
    occuper($this->membre, '2026-12-18', 2);

    // Du 20 déc. 2024 au 20 avr. 2027 : 12 + 365 + 365 + 110 nuits.
    $this->actingAs($this->membre)->get('/statistiques?vue=tout')->assertOk()
        ->assertSee('Depuis le début · du 20 décembre 2024 (première réservation reprise) à aujourd\'hui', false)
        ->assertSeeInOrder(['Nuits occupées', '1 %', '5 nuits sur 852'])
        ->assertSee('week-ends depuis le début')
        ->assertSee('Nuits occupées par année')
        ->assertSeeInOrder(['<tr data-mois="2024"><th scope="row">2024</th><td>3</td>', '<tr data-mois="2025">', '<tr data-mois="2026"><th scope="row">2026</th><td>2</td>', '<tr data-mois="2027">'], false)
        ->assertSee('aria-current="page" class="flex min-h-11 items-center whitespace-nowrap rounded-lg px-2.5 sm:px-3.5 bg-white font-bold">Depuis le début', false)
        ->assertDontSee('name="saison"', false);

    expect(Statistiques::parAnnee(stats()->periode(new DateTimeImmutable('2024-12-20'), new DateTimeImmutable('2027-04-20'))['mois']))
        ->sequence(
            fn ($a) => $a->mois->toBe('2024')->jours->toBe(12)->total->toBe(3)->libres->toBe(9),
            fn ($a) => $a->mois->toBe('2025')->jours->toBe(365)->total->toBe(0),
            fn ($a) => $a->mois->toBe('2026')->jours->toBe(365)->total->toBe(2),
            fn ($a) => $a->mois->toBe('2027')->jours->toBe(110)->total->toBe(0),
        );
});

/** Compte désigné pour les statistiques par lignée (US-711), adresse fictive. */
function compteDesigne(): User
{
    config(['chamrousse.statistiques_lignees' => ' autre@example.test, Pascal@Example.test ']);

    return User::factory()->create(['prenom' => 'Pascal', 'email' => 'pascal@example.test']);
}

it('US-711-P1 montre au compte désigné les nuits, la part, les nuitées-personnes et les réservations par lignée', function () {
    $seconde = Lignee::create(['nom' => 'Seconde lignée', 'couleur' => '#23302A']);
    $this->membre->forceFill(['lignee_id' => $this->lignee->id])->save();
    $sansLignee = User::factory()->create();
    occuper($this->membre, '2026-12-18', 3, 'exclusive', personnes: 4);
    occuper($sansLignee, '2027-01-08', 2, personnes: 2);
    attribuerNuits($seconde, $this->admin, '2027-02-12', 4);

    $this->actingAs(compteDesigne())->get('/statistiques?saison=2026-2027')->assertOk()
        ->assertSee('Visible par vous seul')
        ->assertSeeInOrder([
            'Lignée fictive', '3 n.', '33 % des nuits occupées · 0 ouvertes, 3 exclusives, 0 attribuées · 12 nuitées-personnes · 1 réservation',
            'Seconde lignée', '4 n.', '44 % des nuits occupées · 0 ouvertes, 0 exclusives, 4 attribuées · 0 nuitées-personnes · 0 réservation',
            'Sans lignée', '2 n.', '22 % des nuits occupées · 2 ouvertes, 0 exclusives, 0 attribuées · 4 nuitées-personnes · 1 réservation',
        ]);
});

it('US-711-P2 un admin choisit la lignée d\'un membre, ou aucune, et c\'est journalisé', function () {
    $admin = adminAvec2fa();

    $this->actingAs($admin)->get("/admin/utilisateurs/{$this->membre->id}")->assertOk()
        ->assertSee('Lignée : aucune')->assertSee('Lignée : Lignée fictive');
    $this->actingAs($admin)->put("/admin/utilisateurs/{$this->membre->id}/lignee", ['lignee' => $this->lignee->id])->assertSessionHasNoErrors();
    expect($this->membre->fresh()->lignee_id)->toBe($this->lignee->id)
        ->and(\App\Models\Journal::where('action', 'compte.lignee')->where('objet_id', $this->membre->id)->count())->toBe(1);

    $this->actingAs($admin)->put("/admin/utilisateurs/{$this->membre->id}/lignee", ['lignee' => ''])->assertSessionHasNoErrors();
    expect($this->membre->fresh()->lignee_id)->toBeNull();

    $this->actingAs($admin)->put("/admin/utilisateurs/{$this->membre->id}/lignee", ['lignee' => 99999])->assertSessionHasErrors('lignee');
    $this->actingAs($this->membre)->put("/admin/utilisateurs/{$this->membre->id}/lignee", ['lignee' => $this->lignee->id])->assertForbidden();
});

it('US-711-N1 aucun autre compte, admin compris, ne voit l\'encadré ni un nom de lignée', function (string $cas) {
    $this->membre->forceFill(['lignee_id' => $this->lignee->id])->save();
    occuper($this->membre, '2026-12-18', 3);
    $designe = compteDesigne();
    $compte = match ($cas) {
        'membre' => User::factory()->create(),
        'admin' => $this->admin,
        'réglage vide' => tap($designe, fn () => config(['chamrousse.statistiques_lignees' => ''])),
        'compte désactivé' => tap($designe, fn (User $u) => $u->forceFill(['actif' => false])->save()),
    };

    $page = (string) $this->actingAs($compte)->get('/statistiques?saison=2026-2027')->getContent();
    expect($page)->not->toContain('data-par-lignee')->not->toContain('Lignée fictive')->not->toContain('Sans lignée');
})->with(['membre', 'admin', 'réglage vide', 'compte désactivé']);

it('US-711-N2 une réservation annulée ne compte pas, et aucune adresse de membre n\'apparaît', function () {
    $this->membre->forceFill(['lignee_id' => $this->lignee->id])->save();
    occuper($this->membre, '2026-12-18', 3);
    occuper($this->membre, '2027-01-08', 5, annulee: true);

    $page = $this->actingAs(compteDesigne())->get('/statistiques?saison=2026-2027')->assertOk()
        ->assertSeeInOrder(['Lignée fictive', '3 n.', '0 exclusives, 0 attribuées · 12 nuitées-personnes · 1 réservation'])
        ->getContent();
    // Le prénom et l'initiale apparaissent dans « Par membre » (US-712), jamais le nom complet ni l'e-mail.
    expect(substr((string) $page, (int) strpos((string) $page, '<main')))->not->toContain('Témoin')->not->toContain('zoe@example.test');
});

it('US-712-P1 liste au compte désigné les nuits réservées de chaque membre, du plus au moins', function () {
    $this->membre->forceFill(['lignee_id' => $this->lignee->id])->save();
    $autre = User::factory()->create(['prenom' => 'Hugo', 'nom' => 'Martin']);
    $supprime = User::factory()->create(['prenom' => 'Léa', 'nom' => '', 'supprime_le' => now(), 'actif' => false]);
    occuper($this->membre, '2026-12-18', 2, 'exclusive', personnes: 3);
    occuper($autre, '2027-01-08', 4, personnes: 2);
    occuper($autre, '2027-01-20', 1, personnes: 5);
    occuper($supprime, '2027-03-01', 1, personnes: 1);
    attribuerNuits($this->lignee, $this->admin, '2027-02-12', 4); // ne compte que pour la lignée

    $this->actingAs(compteDesigne())->get('/statistiques?saison=2026-2027')->assertOk()
        ->assertSee('Par membre')
        ->assertSeeInOrder([
            '>Hugo M.</th>', 'Sans lignée', '>5</td>', '>5 · 0</td>', '>13</td>', '>2</td>',
            '>Zoé T.</th>', 'Lignée fictive', '>2</td>', '>0 · 2</td>', '>6</td>', '>1</td>',
            '>Léa</th>', '>1</td>',
        ], false);
});

it('US-712-N1 aucun autre compte ne voit l\'encadré par membre ; ni e-mail ni réservation annulée', function () {
    occuper($this->membre, '2026-12-18', 3);
    occuper($this->membre, '2027-01-08', 5, annulee: true);

    $page = (string) $this->actingAs($this->admin)->get('/statistiques?saison=2026-2027')->getContent();
    expect($page)->not->toContain('data-par-membre')->not->toContain('Zoé T.');

    $page = (string) $this->actingAs(compteDesigne())->get('/statistiques?saison=2026-2027')
        ->assertSeeInOrder(['>Zoé T.</th>', '>3</td>', '>3 · 0</td>', '>12</td>', '>1</td>'], false)->getContent();
    expect($page)->not->toContain('zoe@example.test');
});

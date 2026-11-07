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

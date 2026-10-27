<?php

use App\Domain\Calendrier\EtatCreneau;
use App\Domain\Calendrier\Occupation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-11-02 10:00:00');
    creerCreneaux('2026-11-01', '2026-11-30');
    $this->alice = User::factory()->create(['prenom' => 'Alice', 'nom' => 'Ouverte']);
    $this->bruno = User::factory()->create(['prenom' => 'Bruno', 'nom' => 'Exclusif']);
});

/** Un jeu de données qui couvre les six états. */
function sixEtats(): void
{
    reserverPour(test()->alice, ['2026-11-11-N']);
    reserverPour(test()->bruno, ['2026-11-12-N'], 'exclusive');
    mettreVacances('2026-11-20', '2026-11-20', 'Lignée Bleue');
    mettreVacances('2026-11-21', '2026-11-21');
    DB::table('fermetures')->insert(['debut' => '2026-11-25', 'fin' => '2026-11-25', 'motif' => 'Travaux', 'saisie_par' => test()->bruno->id]);
}

it('US-201-P1 donne à chaque créneau du mois le bon état et le bon libellé', function () {
    sixEtats();

    $situations = app(Occupation::class)->entre(new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30'));
    $attendus = [
        '2026-11-10-J' => EtatCreneau::Libre, '2026-11-11-N' => EtatCreneau::Ouverte,
        '2026-11-12-N' => EtatCreneau::Exclusive,
        '2026-11-20-J' => EtatCreneau::Attribue, '2026-11-20-N' => EtatCreneau::Attribue,
        '2026-11-21-J' => EtatCreneau::Attente, '2026-11-21-N' => EtatCreneau::Attente,
        '2026-11-25-J' => EtatCreneau::Ferme, '2026-11-25-N' => EtatCreneau::Ferme,
    ];
    foreach ($situations as $cle => $s) {
        expect($s->etat)->toBe($attendus[$cle] ?? EtatCreneau::Libre, "créneau {$cle}");
    }
    expect($situations)->toHaveCount(60);

    $page = $this->actingAs($this->alice)->get('/calendrier/2026-11')->assertOk();
    foreach (['Libre', 'Ouvert', 'Exclusif', 'En attente d&#039;attribution', 'Fermé'] as $libelle) {
        $page->assertSee($libelle, false);
    }
    foreach (['libre', 'ouverte', 'exclusive', 'attribue', 'attente', 'ferme'] as $etat) {
        $page->assertSee('data-etat="'.$etat.'"', false);
    }
});

it('US-201-P2 affiche le nom de la lignée sur un créneau attribué', function () {
    mettreVacances('2026-11-20', '2026-11-20', 'Lignée Bleue');

    $this->actingAs($this->alice)->get('/calendrier/2026-11')->assertOk()->assertSee('Lignée Bleue');
});

it('US-201-N1 redirige sans session vers la connexion, sans aucune donnée', function () {
    reserverPour($this->alice, ['2026-11-11-N']);

    $this->get('/calendrier/2026-11')->assertRedirect('/login')->assertDontSee('Alice');
    $this->get('/saison/2026-2027')->assertRedirect('/login');
    $this->get('/jour/2026-11-11')->assertRedirect('/login');
});

it('US-201-N2 renvoie 404 pour un mois hors limites ou mal formé', function (string $mois) {
    $this->actingAs($this->alice)->get('/calendrier/'.$mois)->assertNotFound();
})->with(['2026-13', 'abc', '2026-1', '1999-12', '2101-01', '2026-00']);

it('affiche le mois en cours par défaut et les liens précédent et suivant', function () {
    $this->actingAs($this->alice)->get('/')
        ->assertOk()
        ->assertSee('data-mois="2026-11"', false)
        ->assertSee('/calendrier/2026-10', false)
        ->assertSee('/calendrier/2026-12', false);
});

it('US-202-P1 montre tous les jours du 1er décembre au 30 avril, avec J et N', function () {
    creerCreneaux('2026-12-01', '2027-04-30');

    $html = $this->actingAs($this->alice)->get('/saison/2026-2027')->assertOk()->getContent();

    expect(substr_count($html, 'data-date="'))->toBe(151)
        ->and(substr_count($html, 'data-etat="'))->toBe(302)
        ->and($html)->toContain('data-date="2026-12-01"')->toContain('data-date="2027-04-30"')
        ->and($html)->not->toContain('data-date="2026-11-30"')->not->toContain('data-date="2027-05-01"');
});

it('US-202-P2 marque l\'ouverture et la fermeture de la station', function () {
    creerCreneaux('2026-12-01', '2027-04-30');

    $html = $this->actingAs($this->alice)->get('/saison/2026-2027')->assertOk()->getContent();

    expect($html)->toMatch('/data-date="2026-12-05"(?:(?!data-date).)*data-station="ouverture"/s')
        ->toMatch('/data-date="2027-04-11"(?:(?!data-date).)*data-station="fermeture"/s')
        ->and(substr_count($html, 'data-station="'))->toBe(2);
});

it('US-202-N1 affiche « Pas encore ouvert » pour une saison sans données, sans erreur serveur', function () {
    $this->actingAs($this->alice)->get('/saison/2030-2031')->assertOk()->assertSee('Pas encore ouvert')->assertSee('Aucune donnée');
    $this->actingAs($this->alice)->get('/saison/1999-2000')->assertNotFound();
    $this->actingAs($this->alice)->get('/saison/2026-2028')->assertNotFound();
    $this->actingAs($this->alice)->get('/saison/abc')->assertNotFound();
});

it('montre la saison en cours par défaut', function () {
    $this->actingAs($this->alice)->get('/saison')->assertOk()->assertSee('Saison 2026-2027');
});

it('US-204-P1 liste pour J et N chaque réservation avec réservant, type et nombre de personnes', function () {
    reserverPour($this->alice, ['2026-11-14-J', '2026-11-14-N'], options: ['nombre_personnes' => 4]);
    $carla = User::factory()->create(['prenom' => 'Carla', 'nom' => 'Seconde']);
    reserverPour($carla, ['2026-11-14-N'], options: ['nombre_personnes' => 3]);

    $html = $this->actingAs($this->bruno)->get('/jour/2026-11-14')->assertOk()->getContent();

    [$journee, $nuit] = explode('data-creneau="N"', $html);
    expect($journee)->toContain('Alice Ouverte')->toContain('4 pers.')->toContain('Ouverte')->not->toContain('Carla')
        ->and($nuit)->toContain('Alice Ouverte')->toContain('Carla Seconde')->toContain('3 pers.');
});

it('US-204-P2 ne propose « Me joindre » que sur une réservation ouverte dont je ne fais pas partie', function () {
    reserverPour($this->alice, ['2026-11-14-N']);
    reserverPour($this->bruno, ['2026-11-15-N'], 'exclusive');
    $carla = User::factory()->create();

    $this->actingAs($carla)->get('/jour/2026-11-14')->assertSee('Me joindre');
    $this->actingAs($this->alice)->get('/jour/2026-11-14')->assertDontSee('Me joindre');
    $this->actingAs($carla)->get('/jour/2026-11-15')->assertDontSee('Me joindre');
});

it('US-204-N1 n\'affiche que le nom de la lignée sur un créneau attribué', function () {
    mettreVacances('2026-11-20', '2026-11-20', 'Lignée Bleue');

    $this->actingAs($this->alice)->get('/jour/2026-11-20')->assertOk()
        ->assertSee('Lignée Bleue')
        ->assertDontSee('Bruno')
        ->assertDontSee('data-reservation', false);
});

it('US-204-N2 masque invités et commentaire d\'une réservation exclusive d\'un autre membre', function () {
    reserverPour($this->bruno, ['2026-11-15-N'], 'exclusive', ['invites' => 'Invité Secret', 'commentaire' => 'Commentaire privé']);

    $this->actingAs($this->alice)->get('/jour/2026-11-15')->assertOk()
        ->assertSee('Bruno')->assertDontSee('Invité Secret')->assertDontSee('Commentaire privé');
    $this->actingAs($this->bruno)->get('/jour/2026-11-15')->assertSee('Invité Secret')->assertSee('Commentaire privé');
});

it('US-204-N3 renvoie 404 pour une date mal formée', function (string $date) {
    $this->actingAs($this->alice)->get('/jour/'.$date)->assertNotFound();
})->with(['2026-02-30', 'abc', '2026-11-1', '1999-01-01']);

it('US-205-P1 affiche « Pas encore ouvert » avec l\'explication sur une année non ouverte', function () {
    $this->actingAs($this->alice)->get('/calendrier/2027-11')->assertOk()
        ->assertSee('Pas encore ouvert')
        ->assertSee('vacances de Noël et de février')
        ->assertSee('data-etat="pas-ouvert"', false);
});

it('US-205-P2 affiche « En attente d\'attribution » sur des vacances sans lignée', function () {
    mettreVacances('2026-11-21', '2026-11-22');

    $this->actingAs($this->alice)->get('/jour/2026-11-21')->assertOk()->assertSee('En attente d&#039;attribution', false);
});

it('US-205-N1 ne propose aucune réservation sur ces dates et refuse une requête forgée', function () {
    mettreVacances('2026-11-21', '2026-11-22');

    $this->actingAs($this->alice)->get('/jour/2027-11-10')->assertDontSee('Réserver à partir de ce jour');
    $this->actingAs($this->alice)->get('/jour/2026-11-21')->assertDontSee('Réserver à partir de ce jour');
    $this->actingAs($this->alice)->get('/reserver?debut=2027-11-10&fin=2027-11-11')->assertOk()
        ->assertViewHas('disponibles', fn (array $d) => array_filter($d) === []);

    $this->actingAs($this->alice)->post('/reservations', [
        'debut' => '2027-11-10', 'fin' => '2027-11-11', 'creneaux' => ['2027-11-10-N'], 'mode' => 'ouverte', 'nombre_personnes' => 2,
    ])->assertSessionHasErrors(['reservation' => 'Ces dates ne sont pas encore ouvertes à la réservation.']);
    expect(DB::table('reservations')->count())->toBe(0);
});

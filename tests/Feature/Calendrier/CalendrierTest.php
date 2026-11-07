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

it('US-201-P5 affiche sur chaque créneau réservé le réservant, le nombre de personnes et le commentaire', function () {
    reserverPour($this->alice, ['2026-11-11-N'], options: ['nombre_personnes' => 2, 'commentaire' => 'On monte samedi']);
    reserverPour($this->bruno, ['2026-11-12-N'], 'exclusive', ['nombre_personnes' => 5, 'commentaire' => 'Week-end entre amis']);

    $html = $this->actingAs($this->alice)->get('/calendrier/2026-11')->assertOk()->getContent();
    $case = fn (string $cle) => preg_match('/<div class="case[^>]*data-cle="'.$cle.'".*?<\/div>/s', $html, $m) === 1 ? $m[0] : '';

    expect($case('2026-11-12-N'))->toContain('Bruno E.')->toContain('>5<')->toContain('Week-end entre amis')
        ->and($case('2026-11-11-N'))->toContain('Alice O.')->toContain('>2<')->toContain('On monte samedi');
});

it('US-201-N6 n\'envoie jamais les invités ni un nom de personne sur un créneau attribué, et échappe le commentaire', function () {
    reserverPour($this->alice, ['2026-11-11-N'], options: ['invites' => 'Invité Secret', 'commentaire' => '<b>gras</b>']);
    mettreVacances('2026-11-20', '2026-11-20', 'Lignée Bleue');

    $html = $this->actingAs($this->bruno)->get('/calendrier/2026-11')->assertOk()->getContent();
    preg_match('/<div class="case[^>]*data-cle="2026-11-20-J".*?<\/div>/s', $html, $attribue);

    expect($html)->not->toContain('Invité Secret')->not->toContain('<b>gras</b>')->toContain('&lt;b&gt;gras&lt;/b&gt;')
        ->and($attribue[0])->toContain('Lignée Bleue')->not->toContain('data-reservants');
    $this->get('/saison/2026-2027')->assertOk()->assertDontSee('Invité Secret');
});

it('US-203-P3 affiche sur téléphone le réservant et le nombre de personnes, la lignée seule sur un créneau attribué', function () {
    reserverPour($this->alice, ['2026-11-11-N'], options: ['nombre_personnes' => 2]);
    mettreVacances('2026-11-20', '2026-11-20', 'Lignée Bleue');

    $html = $this->actingAs($this->bruno)->get('/calendrier/2026-11')->assertOk()->getContent();
    $liste = substr($html, (int) strpos($html, 'data-vue="liste"'));

    preg_match('/<div class="case[^>]*data-cle="2026-11-11-N".*?<\/div>/s', $liste, $nuit);
    preg_match('/<div class="case[^>]*data-cle="2026-11-20-J".*?<\/div>/s', $liste, $attribue);

    expect($nuit[0])->toContain('Alice O.')->toContain('>2<')
        ->and($attribue[0])->toContain('Lignée Bleue')->not->toContain('data-reservants');
});

it('rend les créneaux de la liste téléphone sélectionnables, et le jour seul ouvre le détail', function () {
    $html = $this->actingAs($this->alice)->get('/calendrier/2026-11')->assertOk()->getContent();
    $liste = substr($html, (int) strpos($html, 'data-vue="liste"'));

    expect($liste)->toContain('data-rang=')->toContain('data-dispo="libre"')->toContain('/jour/2026-11-12"')
        ->and(substr_count($liste, 'data-cle="2026-11-12-J"'))->toBe(1);
});

it('ne propose pas la vue saison sur téléphone', function () {
    $this->actingAs($this->alice)->get('/calendrier/2026-11')->assertSee('hidden rounded-full bg-segment p-[3px] text-sm min-[600px]:flex', false);
    $this->actingAs($this->alice)->get('/saison/2026-2027')->assertSee('data-vue-saison', false);
});

it('US-301-N8 refuse un commentaire de plus de 200 caractères', function () {
    $this->actingAs($this->alice)->post('/reservations', [
        'debut' => '2026-11-14', 'fin' => '2026-11-14', 'creneaux' => ['2026-11-14-N'], 'mode' => 'ouverte',
        'nombre_personnes' => 2, 'commentaire' => str_repeat('a', 201),
    ])->assertSessionHasErrors('commentaire');
    $this->assertDatabaseCount('reservations', 0);
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

it('US-202-P7 ouvre la vue saison pendant la saison de la station, la vue mois sinon', function (string $jour, string $attendu) {
    DB::table('parametres')->upsert([
        ['cle' => 'station.ouverture.2026-2027', 'valeur' => '2026-12-05'],
        ['cle' => 'station.fermeture.2026-2027', 'valeur' => '2027-04-11'],
    ], ['cle'], ['valeur']);
    $this->travelTo($jour.' 10:00:00');

    $this->actingAs($this->alice)->get('/')->assertOk()->assertSee($attendu, false);
})->with([
    ['2026-12-04', 'data-mois="2026-12"'], ['2026-12-05', 'Saison 2026-2027'],
    ['2027-04-11', 'Saison 2026-2027'], ['2027-04-12', 'data-mois="2027-04"'],
]);

it('US-202-P6 prend du 1er décembre au 30 avril si les dates de la station ne sont pas saisies', function () {
    // Saison 2027-2028 : aucune date de station saisie ni par défaut.
    $this->travelTo('2027-12-01 10:00:00');
    $this->actingAs($this->alice)->get('/')->assertOk()->assertSee('Saison 2027-2028');
    $this->travelTo('2027-11-30 10:00:00');
    $this->actingAs($this->alice)->get('/')->assertOk()->assertSee('data-mois="2027-11"', false);
});

it('reste sur la vue du calendrier après une réservation faite depuis la pop-up', function () {
    $demande = ['debut' => '2026-11-14', 'fin' => '2026-11-14', 'creneaux' => ['2026-11-14-N'], 'mode' => 'ouverte', 'nombre_personnes' => 2];

    $this->actingAs($this->alice)->post('/reservations', $demande + ['retour' => '/calendrier/2026-11'])->assertRedirect('/calendrier/2026-11');
    $autre = ['creneaux' => ['2026-11-15-N'], 'debut' => '2026-11-15', 'fin' => '2026-11-15'] + $demande;
    $this->actingAs($this->bruno)->post('/reservations', $autre + ['retour' => '/'])->assertRedirect('/');
    $carla = User::factory()->create();
    $piege = ['creneaux' => ['2026-11-16-N'], 'debut' => '2026-11-16', 'fin' => '2026-11-16'] + $demande;
    $this->actingAs($carla)->post('/reservations', $piege + ['retour' => '//exemple.test/piege'])->assertRedirect('/mes-reservations');
});

it('ouvre en édition dans la pop-up un créneau de ma réservation, jamais celui d\'un autre', function () {
    $mienne = reserverPour($this->alice, ['2026-11-14-J', '2026-11-14-N'], options: ['nombre_personnes' => 3, 'commentaire' => 'Ski', 'invites' => 'Paul']);
    reserverPour($this->bruno, ['2026-11-20-N']);

    $html = $this->actingAs($this->alice)->get('/calendrier/2026-11')->assertOk()->getContent();
    $case = fn (string $cle) => preg_match('/<div class="case[^>]*data-cle="'.$cle.'"[^>]*>/s', $html, $m) === 1 ? $m[0] : '';

    expect($case('2026-11-14-N'))->toContain('data-dispo="mienne"')->toContain('data-mienne="'.$mienne->id.'"')
        ->toContain('data-creneaux="2026-11-14-J,2026-11-14-N"')->toContain('data-nb="3"')->toContain('data-invites="Paul"')
        ->and($case('2026-11-20-N'))->toContain('data-dispo="non"')->not->toContain('data-mienne');
    // Bruno ne reçoit ni l'édition ni les invités d'Alice.
    $this->actingAs($this->bruno)->get('/calendrier/2026-11')->assertDontSee('data-mienne="'.$mienne->id.'"', false)->assertDontSee('Paul');
});

it('reste sur la vue après une modification faite depuis la pop-up', function () {
    $r = reserverPour($this->alice, ['2026-11-14-N']);

    $this->actingAs($this->alice)->put("/reservations/{$r->id}", [
        'debut' => '2026-11-14', 'fin' => '2026-11-14', 'creneaux' => ['2026-11-14-N'], 'mode' => 'exclusive',
        'nombre_personnes' => 4, 'commentaire' => 'Changé', 'retour' => '/calendrier/2026-11',
    ])->assertRedirect('/calendrier/2026-11');

    expect($r->fresh())->mode->toBe('exclusive')->nombre_personnes->toBe(4)->commentaire->toBe('Changé');
});

it('annule ma réservation depuis la pop-up et reste sur la vue ; le bouton figure aussi sur l\'écran de modification', function () {
    $r = reserverPour($this->alice, ['2026-11-14-N']);

    $this->actingAs($this->alice)->get('/calendrier/2026-11')->assertSee('data-zone-annulation', false)->assertSee('Annuler la réservation');
    $this->actingAs($this->alice)->get("/reservations/{$r->id}/modifier")->assertOk()
        ->assertSee('Annuler la réservation')->assertSee("Confirmer l'annulation", false);

    $this->actingAs($this->alice)->delete("/reservations/{$r->id}", ['retour' => '/calendrier/2026-11'])->assertRedirect('/calendrier/2026-11');
    expect($r->fresh()->annulee_le)->not->toBeNull();
});

it('rouvre la pop-up sur la même période avec la raison quand le serveur refuse', function () {
    $this->actingAs($this->alice)->from('/calendrier/2026-11')->post('/reservations', [
        'debut' => '2026-11-14', 'fin' => '2026-11-14', 'creneaux' => ['2026-11-14-N'], 'mode' => 'ouverte',
        'nombre_personnes' => 2, 'commentaire' => str_repeat('a', 201), 'retour' => '/calendrier/2026-11',
    ])->assertRedirect('/calendrier/2026-11');

    $this->actingAs($this->alice)->get('/calendrier/2026-11')->assertOk()
        ->assertSee('data-anciens="2026-11-14-N"', false)
        ->assertSee('Le commentaire ne doit pas dépasser 200 caractères.');
});

it('affiche le mois en cours par défaut et les liens précédent et suivant', function () {
    $this->actingAs($this->alice)->get('/calendrier')
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

it('US-202-P4 regroupe les créneaux consécutifs d\'une réservation en une barre ; un créneau isolé porte les initiales', function () {
    creerCreneaux('2026-12-01', '2027-04-30');
    reserverPour($this->bruno, ['2026-12-11-N', '2026-12-12-J', '2026-12-12-N', '2026-12-13-J'], 'exclusive', ['nombre_personnes' => 5, 'commentaire' => 'Week-end entre amis']);
    reserverPour($this->alice, ['2026-12-17-N'], options: ['nombre_personnes' => 2]);

    $html = $this->actingAs($this->alice)->get('/saison/2026-2027')->assertOk()->getContent();

    expect($html)->toMatch('/class="barre barre-exclusive[^"]*row-start-3 col-start-12 col-span-2[^"]*"[^>]*>\s*<span[^>]*>Bruno E\. · 5<\/span>\s*<span[^>]*>Week-end entre amis<\/span>/')
        ->toMatch('/class="barre barre-exclusive[^"]*row-start-4 col-start-11 col-span-2/')
        ->toMatch('/class="barre barre-ouverte[^"]*row-start-4 col-start-17 col-span-1[^"]*"[^>]*>\s*<span[^>]*>AO<\/span>\s*<span[^>]*>2<\/span>/');
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
    reserverPour($this->alice, ['2026-11-14-J'], options: ['nombre_personnes' => 4]);
    $carla = User::factory()->create(['prenom' => 'Carla', 'nom' => 'Seconde']);
    reserverPour($carla, ['2026-11-14-N'], 'exclusive', ['nombre_personnes' => 3]);

    $html = $this->actingAs($this->bruno)->get('/jour/2026-11-14')->assertOk()->getContent();

    [$journee, $nuit] = explode('data-creneau="N"', $html);
    expect($journee)->toContain('Alice Ouverte')->toContain('4 pers.')->toContain('Ouverte')->not->toContain('Carla')
        ->and($nuit)->toContain('Carla Seconde')->toContain('3 pers.')->toContain('Exclusive')->not->toContain('Alice');
});

it('US-204-N1 n\'affiche que le nom de la lignée sur un créneau attribué', function () {
    mettreVacances('2026-11-20', '2026-11-20', 'Lignée Bleue');

    $this->actingAs($this->alice)->get('/jour/2026-11-20')->assertOk()
        ->assertSee('Lignée Bleue')
        ->assertDontSee('Bruno')
        ->assertDontSee('data-reservation', false);
});

it('US-204-N2 masque les invités d\'une réservation d\'un autre membre, mais montre le commentaire à tous', function () {
    reserverPour($this->bruno, ['2026-11-15-N'], 'exclusive', ['invites' => 'Invité Secret', 'commentaire' => 'Commentaire visible']);
    reserverPour($this->bruno, ['2026-11-16-N'], 'ouverte', ['invites' => 'Autre Invité']);

    $this->actingAs($this->alice)->get('/jour/2026-11-15')->assertOk()
        ->assertSee('Bruno')->assertDontSee('Invité Secret')->assertSee('Commentaire visible');
    $this->actingAs($this->alice)->get('/jour/2026-11-16')->assertDontSee('Autre Invité');
    $this->actingAs($this->bruno)->get('/jour/2026-11-15')->assertSee('Invité Secret')->assertSee('Commentaire visible');
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


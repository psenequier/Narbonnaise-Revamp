<?php

use App\Models\Journal;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->travelTo('2026-11-02 10:00:00');
    creerCreneaux('2026-11-01', '2026-11-30');
    $this->alice = User::factory()->create(['prenom' => 'Alice']);
    $this->bruno = User::factory()->create(['prenom' => 'Bruno']);
});

/** Corps d'une demande valide, à ajuster par test. */
function demandeHttp(array $creneaux = ['2026-11-14-N', '2026-11-15-J'], array $autres = []): array
{
    $dates = array_map(fn ($c) => substr($c, 0, 10), $creneaux ?: ['2026-11-14']);

    return $autres + [
        'debut' => min($dates), 'fin' => max($dates), 'creneaux' => $creneaux,
        'mode' => 'ouverte', 'nombre_personnes' => 3,
    ];
}

function etatsDe(string ...$cles): array
{
    $s = app(\App\Domain\Calendrier\Occupation::class)->entre(new DateTimeImmutable('2026-11-01'), new DateTimeImmutable('2026-11-30'));

    return array_map(fn ($c) => $s[$c]->etat->value, $cles);
}

it('US-301-P1 crée une réservation ouverte sur des créneaux libres', function () {
    $this->actingAs($this->alice)->post('/reservations', demandeHttp())
        ->assertSessionHasNoErrors()->assertRedirect('/mes-reservations');

    $r = Reservation::sole();
    expect($r->user_id)->toBe($this->alice->id)->and($r->nombre_personnes)->toBe(3)
        ->and(etatsDe('2026-11-14-N', '2026-11-15-J'))->toBe(['ouverte', 'ouverte']);
});

it('US-301-P2 accepte une réservation sur des créneaux déjà ouverts : les deux réservants apparaissent', function () {
    reserverPour($this->alice, ['2026-11-14-N']);

    $this->actingAs($this->bruno)->post('/reservations', demandeHttp(['2026-11-14-N']))->assertSessionHasNoErrors();

    $this->get('/jour/2026-11-14')->assertSee('Alice')->assertSee('Bruno');
});

it('US-301-P3 trace la création dans le journal', function () {
    $this->actingAs($this->alice)->post('/reservations', demandeHttp());

    expect(Journal::where('action', 'reservation.creee')->where('user_id', $this->alice->id)->exists())->toBeTrue();
});

it('US-301-N3 refuse une date passée', function () {
    $this->actingAs($this->alice)->post('/reservations', demandeHttp(['2026-11-01-N']))
        ->assertSessionHasErrors(['reservation' => 'Ce créneau est déjà passé.']);
    expect(Reservation::count())->toBe(0);
});

it('US-301-N5 refuse un nombre de personnes nul, négatif, non numérique ou supérieur à 50', function (mixed $nombre) {
    $this->actingAs($this->alice)->post('/reservations', demandeHttp(autres: ['nombre_personnes' => $nombre]))
        ->assertSessionHasErrors('nombre_personnes');
    expect(Reservation::count())->toBe(0);
})->with([0, -1, 'abc', 51, '']);

it('US-301-N6 refuse une fin antérieure au début, ou aucun créneau coché', function () {
    $this->actingAs($this->alice)->post('/reservations', demandeHttp(autres: ['debut' => '2026-11-15', 'fin' => '2026-11-14']))
        ->assertSessionHasErrors('fin');
    $this->actingAs($this->alice)->post('/reservations', demandeHttp(autres: ['creneaux' => []]))
        ->assertSessionHasErrors('creneaux');
    $this->actingAs($this->alice)->post('/reservations', demandeHttp(autres: ['fin' => '2026-11-14', 'creneaux' => ['2026-11-20-N']]))
        ->assertSessionHasErrors('creneaux');
    expect(Reservation::count())->toBe(0);
});

it('US-301-N7 affiche échappés un commentaire et des invités contenant du HTML', function () {
    $piege = '<img src=x onerror=alert(1)>';
    $this->actingAs($this->alice)->post('/reservations', demandeHttp(autres: ['commentaire' => $piege, 'invites' => $piege]))
        ->assertSessionHasNoErrors();

    expect(Reservation::sole()->commentaire)->toBe($piege);
    $this->actingAs($this->bruno)->get('/jour/2026-11-14')
        ->assertSee('&lt;img src=x onerror=alert(1)&gt;', false)
        ->assertDontSee($piege, false);
});

it('US-302-P1 crée une réservation exclusive sur des créneaux libres', function () {
    $this->actingAs($this->alice)->post('/reservations', demandeHttp(autres: ['mode' => 'exclusive']))->assertSessionHasNoErrors();

    expect(etatsDe('2026-11-14-N', '2026-11-15-J'))->toBe(['exclusive', 'exclusive']);
});

it('US-302-N1 refuse l\'exclusif si un seul créneau est déjà ouvert par quelqu\'un', function () {
    reserverPour($this->bruno, ['2026-11-15-J']);

    $this->actingAs($this->alice)->post('/reservations', demandeHttp(autres: ['mode' => 'exclusive']))
        ->assertSessionHasErrors('reservation');
    expect($this->alice->reservations()->count())->toBe(0);
});

it('US-302-N2 refuse toute autre réservation, ouverte ou exclusive, sur des créneaux exclusifs', function (string $mode) {
    reserverPour($this->bruno, ['2026-11-14-N'], 'exclusive');

    $this->actingAs($this->alice)->post('/reservations', demandeHttp(['2026-11-14-N'], ['mode' => $mode]))
        ->assertSessionHasErrors('reservation');
    expect($this->alice->reservations()->count())->toBe(0);
})->with(['ouverte', 'exclusive']);

it('US-302-N3 applique aussi à l\'exclusif les règles de dates, de personnes et de créneaux', function (array $creneaux, array $autres, string $champ) {
    $this->actingAs($this->alice)->post('/reservations', demandeHttp($creneaux, $autres + ['mode' => 'exclusive']))
        ->assertSessionHasErrors($champ);
    expect(Reservation::count())->toBe(0);
})->with([
    'date passée' => [['2026-11-01-N'], [], 'reservation'],
    'année non ouverte' => [['2027-11-10-N'], [], 'reservation'],
    'trop de personnes' => [['2026-11-14-N'], ['nombre_personnes' => 51], 'nombre_personnes'],
    'aucun créneau' => [[], ['creneaux' => []], 'creneaux'],
]);

it('US-302-N3 refuse aussi l\'exclusif sur des vacances ou une fermeture', function () {
    mettreVacances('2026-11-21', '2026-11-22');
    DB::table('fermetures')->insert(['debut' => '2026-11-25', 'fin' => '2026-11-25', 'motif' => 'Travaux', 'saisie_par' => $this->bruno->id]);

    $this->actingAs($this->alice)->post('/reservations', demandeHttp(['2026-11-21-N'], ['mode' => 'exclusive']))->assertSessionHasErrors('reservation');
    $this->actingAs($this->alice)->post('/reservations', demandeHttp(['2026-11-25-J'], ['mode' => 'exclusive']))->assertSessionHasErrors('reservation');
    expect(Reservation::count())->toBe(0);
});

it('US-303-N2 refuse côté serveur une validation forgée qui contourne l\'encadré', function () {
    reserverPour($this->bruno, ['2026-11-14-N']);

    $this->actingAs($this->alice)->get('/reserver?debut=2026-11-14&fin=2026-11-15')
        ->assertOk()
        ->assertViewHas('raisonPasExclusif', fn ($r) => str_contains((string) $r, 'Bruno'));

    $this->actingAs($this->alice)->post('/reservations', demandeHttp(autres: ['mode' => 'exclusive']))->assertSessionHasErrors('reservation');
    expect($this->alice->reservations()->count())->toBe(0);
});

it('affiche l\'encadré « Aucun conflit » sur des créneaux tous libres', function () {
    $this->actingAs($this->alice)->get('/reserver?debut=2026-11-14&fin=2026-11-16')
        ->assertOk()->assertSee('Aucun conflit')
        ->assertViewHas('raisonPasExclusif', null)
        ->assertViewHas('coches', ['2026-11-14-N', '2026-11-15-J', '2026-11-15-N', '2026-11-16-J']);
});

it('préremplit « Me joindre » avec les créneaux de la réservation rejointe', function () {
    $r = reserverPour($this->bruno, ['2026-11-14-N', '2026-11-15-J']);

    $this->actingAs($this->alice)->get('/reserver?joindre='.$r->id)->assertOk()
        ->assertSee('Vous rejoignez la réservation de')
        ->assertViewHas('coches', ['2026-11-14-N', '2026-11-15-J']);
});

it('US-304-N1 refuse de se joindre à une réservation exclusive, même par requête forgée', function () {
    $r = reserverPour($this->bruno, ['2026-11-14-N'], 'exclusive');

    $this->actingAs($this->alice)->get('/reserver?joindre='.$r->id)->assertViewHas('coches', []);
    $this->actingAs($this->alice)->post('/reservations', demandeHttp(['2026-11-14-N']))->assertSessionHasErrors('reservation');
    expect($this->alice->reservations()->count())->toBe(0);
});

it('US-304-N2 refuse de se joindre deux fois aux mêmes créneaux', function () {
    reserverPour($this->bruno, ['2026-11-14-N']);
    $this->actingAs($this->alice)->post('/reservations', demandeHttp(['2026-11-14-N']))->assertSessionHasNoErrors();

    $this->actingAs($this->alice)->post('/reservations', demandeHttp(['2026-11-14-N']))
        ->assertSessionHasErrors(['reservation' => 'Vous êtes déjà inscrit sur un de ces créneaux.']);
    expect($this->alice->reservations()->count())->toBe(1);
});

it('US-305-P1 enregistre une modification : créneaux libérés, nouveaux occupés, journal avant et après', function () {
    $r = reserverPour($this->alice, ['2026-11-14-N', '2026-11-15-J']);

    $this->actingAs($this->alice)->put("/reservations/{$r->id}", demandeHttp(['2026-11-15-J', '2026-11-15-N'], ['nombre_personnes' => 5]))
        ->assertSessionHasNoErrors()->assertRedirect('/mes-reservations');

    expect(etatsDe('2026-11-14-N', '2026-11-15-J', '2026-11-15-N'))->toBe(['libre', 'ouverte', 'ouverte'])
        ->and($r->fresh()->nombre_personnes)->toBe(5);
    $trace = Journal::where('action', 'reservation.modifiee')->where('objet_id', $r->id)->sole();
    expect($trace->details['avant']['creneaux'])->toBe(['2026-11-14-N', '2026-11-15-J'])
        ->and($trace->details['apres']['creneaux'])->toBe(['2026-11-15-J', '2026-11-15-N'])
        ->and($trace->details['avant']['nombre_personnes'])->toBe(2);
});

it('US-305-P2 accepte le passage d\'ouverte à exclusive si je suis seul', function () {
    $r = reserverPour($this->alice, ['2026-11-14-N']);

    $this->actingAs($this->alice)->put("/reservations/{$r->id}", demandeHttp(['2026-11-14-N'], ['mode' => 'exclusive']))->assertSessionHasNoErrors();

    expect($r->fresh()->mode)->toBe('exclusive');
});

it('US-305-N1 interdit de modifier la réservation d\'un autre (403), sans rien changer', function () {
    $r = reserverPour($this->bruno, ['2026-11-14-N']);

    $this->actingAs($this->alice)->put("/reservations/{$r->id}", demandeHttp(['2026-11-20-N']))->assertForbidden();
    $this->actingAs($this->alice)->get("/reservations/{$r->id}/modifier")->assertForbidden();

    expect($r->fresh()->creneaux->pluck('date')->map->format('Y-m-d')->all())->toBe(['2026-11-14']);
});

it('US-305-N2 refuse une modification vers des créneaux en conflit et garde l\'original', function () {
    $r = reserverPour($this->alice, ['2026-11-14-N']);
    reserverPour($this->bruno, ['2026-11-20-N'], 'exclusive');

    $this->actingAs($this->alice)->put("/reservations/{$r->id}", demandeHttp(['2026-11-20-N']))->assertSessionHasErrors('reservation');

    expect(etatsDe('2026-11-14-N'))->toBe(['ouverte']);
});

it('US-305-N3 refuse de modifier une réservation entièrement passée', function () {
    $r = reserverPour($this->alice, ['2026-11-03-N']);
    $this->travelTo('2026-11-05 10:00:00');

    $this->actingAs($this->alice)->put("/reservations/{$r->id}", demandeHttp(['2026-11-20-N']))
        ->assertSessionHasErrors('reservation');
    expect(etatsDe('2026-11-03-N', '2026-11-20-N'))->toBe(['ouverte', 'libre']);
});

it('US-305-N4 refuse le passage à exclusive si d\'autres participants sont inscrits', function () {
    $r = reserverPour($this->alice, ['2026-11-14-N']);
    reserverPour($this->bruno, ['2026-11-14-N']);

    $this->actingAs($this->alice)->put("/reservations/{$r->id}", demandeHttp(['2026-11-14-N'], ['mode' => 'exclusive']))
        ->assertSessionHasErrors('reservation');
    expect($r->fresh()->mode)->toBe('ouverte');
});

it('US-306-P1 libère les créneaux, ou les laisse ouverts s\'il reste un participant, et journalise', function () {
    $seule = reserverPour($this->alice, ['2026-11-14-N']);
    $partagee = reserverPour($this->alice, ['2026-11-20-N']);
    reserverPour($this->bruno, ['2026-11-20-N']);

    $this->actingAs($this->alice)->delete("/reservations/{$seule->id}")->assertRedirect('/mes-reservations');
    $this->actingAs($this->alice)->delete("/reservations/{$partagee->id}")->assertRedirect('/mes-reservations');

    expect(etatsDe('2026-11-14-N', '2026-11-20-N'))->toBe(['libre', 'ouverte'])
        ->and(Journal::where('action', 'reservation.annulee')->count())->toBe(2);
});

it('US-306-N1 interdit d\'annuler la réservation d\'un autre (403)', function () {
    $r = reserverPour($this->bruno, ['2026-11-14-N']);

    $this->actingAs($this->alice)->delete("/reservations/{$r->id}")->assertForbidden();
    expect($r->fresh()->annulee_le)->toBeNull();
});

it('US-306-N2 refuse d\'annuler une réservation déjà annulée ou passée', function () {
    $r = reserverPour($this->alice, ['2026-11-14-N']);
    $this->actingAs($this->alice)->delete("/reservations/{$r->id}");
    $this->actingAs($this->alice)->delete("/reservations/{$r->id}")->assertSessionHasErrors('reservation');

    $passee = reserverPour($this->alice, ['2026-11-03-N']);
    $this->travelTo('2026-11-05 10:00:00');
    $this->actingAs($this->alice)->delete("/reservations/{$passee->id}")->assertSessionHasErrors('reservation');
    expect($passee->fresh()->annulee_le)->toBeNull();
});

it('US-306-N3 refuse une annulation sans jeton CSRF (419)', function () {
    $r = reserverPour($this->alice, ['2026-11-14-N']);

    $this->app['env'] = 'local';
    try {
        $this->actingAs($this->alice)->delete("/reservations/{$r->id}")->assertStatus(419);
    } finally {
        $this->app['env'] = 'testing';
    }
    expect($r->fresh()->annulee_le)->toBeNull();
});

it('US-307-P1 liste mes réservations à venir triées par date, avec Modifier et Annuler', function () {
    $tard = reserverPour($this->alice, ['2026-11-20-N']);
    $tot = reserverPour($this->alice, ['2026-11-10-N']);

    $html = $this->actingAs($this->alice)->get('/mes-reservations')->assertOk()->assertSee('Modifier')->assertSee('Annuler')->getContent();

    expect(strpos($html, 'data-reservation="'.$tot->id.'"'))->toBeLessThan(strpos($html, 'data-reservation="'.$tard->id.'"'));
});

it('US-307-P2 montre dans l\'historique les réservations reprises de GRR', function () {
    $r = reserverPour($this->alice, ['2026-11-03-N']);
    $r->forceFill(['grr_id' => 4242])->save();
    $this->travelTo('2026-11-05 10:00:00');

    $html = $this->actingAs($this->alice)->get('/mes-reservations')->assertOk()->getContent();

    expect(explode('id="passees"', $html)[1])->toContain('data-reservation="'.$r->id.'"')->toContain("reprise de l'ancien site");
});

it('US-307-N1 ne montre jamais les réservations d\'un autre membre', function () {
    $autre = reserverPour($this->bruno, ['2026-11-14-N']);

    $this->actingAs($this->alice)->get('/mes-reservations')->assertOk()->assertDontSee('data-reservation="'.$autre->id.'"', false);
});

it('US-307-N2 ne propose ni Modifier ni Annuler sur une réservation passée', function () {
    reserverPour($this->alice, ['2026-11-03-N']);
    $this->travelTo('2026-11-05 10:00:00');

    $this->actingAs($this->alice)->get('/mes-reservations')->assertOk()
        ->assertDontSee('/modifier', false)->assertDontSee("Confirmer l'annulation", false);
});

<?php

use App\Domain\Statistiques\Statistiques;
use App\Http\Controllers\CalendrierController;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\Notification;

/* US-1003 · Consulter le calendrier et les statistiques par mon assistant. */

beforeEach(function () {
    $this->travelTo('2026-11-02 10:00:00');
    Notification::fake();
    creerCreneaux('2026-11-01', '2026-11-30');
    $this->alice = User::factory()->create(['prenom' => 'Alice', 'nom' => 'Martin']);
    $this->bruno = User::factory()->create(['prenom' => 'Bruno', 'nom' => 'Durand']);
    $this->jeton = connecterAssistant($this->alice)['access_token'];
});

it('US-1003-P1 renvoie les mêmes chiffres que la page Statistiques', function () {
    reserverPour($this->bruno, ['2026-11-14-N', '2026-11-15-J'], 'ouverte', ['nombre_personnes' => 4]);

    $resultat = outilMcp($this->jeton, 'statistiques', ['vue' => 'annee', 'annee' => 2026]);
    $attendu = app(Statistiques::class)->periode(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-12-31'));

    expect($resultat['isError'])->toBeFalse()
        ->and($resultat['structuredContent']['chiffres'])->toBe(json_decode(json_encode($attendu), true))
        ->and($resultat['structuredContent']['chiffres']['nuits'])->toBe(1)
        ->and($resultat['content'][0]['type'])->toBe('text');

    $saison = outilMcp($this->jeton, 'statistiques')['structuredContent'];
    $a = Statistiques::anneeDeSaison(CalendrierController::aujourdhui());
    expect($saison['periode'])->toBe($a.'-'.($a + 1));
});

it('US-1003-P1 vue « depuis le début », et chiffres par lignée pour le seul compte désigné, comme sur la page', function () {
    reserverPour($this->bruno, ['2026-11-14-N'], 'ouverte', ['nombre_personnes' => 2]);
    $this->travelTo('2026-11-20 10:00:00');
    $this->jeton = connecterAssistant($this->alice)['access_token'];

    $tout = outilMcp($this->jeton, 'statistiques', ['vue' => 'tout'])['structuredContent'];
    expect($tout)->toMatchArray(['vue' => 'tout', 'du' => '2026-11-14', 'au' => '2026-11-20'])
        ->and($tout['chiffres']['nuits'])->toBe(1)
        ->and($tout)->not->toHaveKey('par_lignee')->not->toHaveKey('par_membre');

    config(['chamrousse.statistiques_lignees' => 'designe@example.test']);
    $designe = User::factory()->create(['prenom' => 'Désigné', 'email' => 'designe@example.test']);
    $jeton = connecterAssistant($designe)['access_token'];
    $vu = outilMcp($jeton, 'statistiques', ['vue' => 'tout'])['structuredContent'];

    expect($vu)->toHaveKeys(['par_lignee', 'par_membre'])
        ->and(collect($vu['par_membre'])->sum('total'))->toBe(1)
        ->and(json_encode($vu))->not->toContain('@example.test');
    expect(outilMcp($this->jeton, 'statistiques', ['vue' => 'tout'])['structuredContent'])->not->toHaveKey('par_lignee');
});

it('US-1003-P2 renvoie l\'état de chaque journée et nuit, l\'auteur et le nombre de personnes', function () {
    reserverPour($this->bruno, ['2026-11-14-N'], 'exclusive', ['nombre_personnes' => 3]);
    mettreVacances('2026-11-20', '2026-11-22', 'Lignée Test');

    $jours = collect(outilMcp($this->jeton, 'calendrier', ['du' => '2026-11-14', 'au' => '2026-11-21'])['structuredContent']['jours'])->keyBy('date');

    expect($jours['2026-11-14']['nuit'])->toMatchArray(['creneau' => '2026-11-14-N', 'etat' => 'exclusive'])
        ->and($jours['2026-11-14']['nuit']['reservations'][0])->toMatchArray(['prenom' => 'Bruno', 'nom' => 'Durand', 'nombre_personnes' => 3, 'vous' => false])
        ->and($jours['2026-11-14']['journee']['etat'])->toBe('libre')
        ->and($jours['2026-11-20']['nuit'])->toMatchArray(['etat' => 'attribue', 'lignee' => 'Lignée Test'])
        ->and($jours)->toHaveCount(8);
});

it('US-1003-P3 renvoie mes réservations à venir et passées, avec mes commentaires', function () {
    reserverPour($this->alice, ['2026-11-14-N', '2026-11-15-J'], 'ouverte', ['commentaire' => 'On monte les skis']);
    $this->travelTo('2026-11-20 10:00:00');
    reserverPour($this->alice, ['2026-11-25-N'], 'exclusive');
    $jeton = connecterAssistant($this->alice)['access_token'];

    $liste = outilMcp($jeton, 'mes_reservations')['structuredContent']['reservations'];

    expect($liste)->toHaveCount(2)
        ->and($liste[0])->toMatchArray(['creneaux' => ['2026-11-14-N', '2026-11-15-J'], 'commentaire' => 'On monte les skis', 'a_venir' => false])
        ->and($liste[1])->toMatchArray(['creneaux' => ['2026-11-25-N'], 'mode' => 'exclusive', 'a_venir' => true]);
});

it('US-1003-P4 renvoie les créneaux libres et à venir', function () {
    reserverPour($this->bruno, ['2026-11-14-N']);

    $libres = outilMcp($this->jeton, 'disponibilites', ['du' => '2026-11-01', 'au' => '2026-11-15'])['structuredContent'];

    expect($libres['creneaux_libres'])->toContain('2026-11-02-J', '2026-11-14-J', '2026-11-15-N')
        ->not->toContain('2026-11-14-N', '2026-11-01-N')
        ->and($libres['nuits_libres'])->toContain('2026-11-13')->not->toContain('2026-11-14');
});

it('US-1003-P5 note chaque appel au journal, sans la réponse', function () {
    outilMcp($this->jeton, 'calendrier', ['du' => '2026-11-14', 'au' => '2026-11-15']);

    $ligne = Journal::where('action', 'assistant.appel')->sole();
    expect($ligne->user_id)->toBe($this->alice->id)
        ->and($ligne->details)->toMatchArray(['outil' => 'calendrier', 'application' => 'Claude', 'du' => '2026-11-14', 'au' => '2026-11-15'])
        ->and(json_encode($ligne->details))->not->toContain('jours');
});

it('US-1003-N1 ne transmet ni e-mail, ni commentaire d\'un autre, ni réservation annulée', function () {
    reserverPour($this->bruno, ['2026-11-14-N'], 'ouverte', ['commentaire' => 'Code du portail 1234']);
    $annulee = reserverPour($this->bruno, ['2026-11-16-N'], 'ouverte', ['commentaire' => 'Annulée']);
    app(\App\Domain\Reservations\ServiceReservation::class)->annuler($annulee, new DateTimeImmutable('2026-11-02 10:00:00'));

    $reponse = appelMcp($this->jeton, 'tools/call', ['name' => 'calendrier', 'arguments' => ['du' => '2026-11-01', 'au' => '2026-11-30']]);
    $texte = $reponse->getContent();

    expect($texte)->toContain('Bruno')
        ->not->toContain($this->bruno->email)->not->toContain($this->alice->email)
        ->not->toContain('Code du portail')->not->toContain('Annulée')->not->toContain('"commentaire"');
    $jours = collect($reponse->json('result.structuredContent.jours'))->keyBy('date');
    expect($jours['2026-11-16']['nuit']['etat'])->toBe('libre');
});

it('US-1003-N2 refuse une période invalide ou de plus de 400 jours avec un message clair', function () {
    $trop = outilMcp($this->jeton, 'calendrier', ['du' => '2026-01-01', 'au' => '2027-03-01']);
    $mal = outilMcp($this->jeton, 'disponibilites', ['du' => '2026-02-30', 'au' => '2026-03-01']);
    $envers = outilMcp($this->jeton, 'calendrier', ['du' => '2026-11-10', 'au' => '2026-11-01']);

    expect($trop)->toMatchArray(['isError' => true])->not->toHaveKey('structuredContent')
        ->and($trop['content'][0]['text'])->toBe('Période trop longue : 400 jours au plus.')
        ->and($mal['content'][0]['text'])->toBe('Donnez « du » et « au » au format AAAA-MM-JJ.')
        ->and($envers['isError'])->toBeTrue();
});

it('US-1003-N3 refuse au-delà de 60 appels par minute, sans données', function () {
    for ($i = 0; $i < 60; $i++) {
        appelMcp($this->jeton, 'ping')->assertOk();
    }

    appelMcp($this->jeton, 'tools/list')->assertStatus(429)->assertHeader('Retry-After')->assertJsonMissingPath('result');

    $this->travel(61)->seconds();
    appelMcp($this->jeton, 'ping')->assertOk();
});

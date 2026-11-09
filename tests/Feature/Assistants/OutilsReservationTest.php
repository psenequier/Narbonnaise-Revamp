<?php

use App\Models\Journal;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/* US-1004 · Réserver, modifier et annuler par mon assistant. */

beforeEach(function () {
    $this->travelTo('2026-11-02 10:00:00');
    Notification::fake();
    creerCreneaux('2026-11-01', '2026-11-30');
    $this->alice = User::factory()->create(['prenom' => 'Alice']);
    $this->bruno = User::factory()->create(['prenom' => 'Bruno']);
    $this->jeton = connecterAssistant($this->alice, reservation: true)['access_token'];
});

it('US-1004-P1 réserve au nom du membre avec les règles et les e-mails du site', function () {
    $resultat = outilMcp($this->jeton, 'reserver', [
        'creneaux' => ['2026-11-14-N', '2026-11-15-J'], 'mode' => 'ouverte', 'nombre_personnes' => 4, 'commentaire' => 'Avec les enfants',
    ]);

    expect($resultat['isError'])->toBeFalse();
    $r = Reservation::with('creneaux')->sole();
    expect($r->user_id)->toBe($this->alice->id)->and($r->nombre_personnes)->toBe(4)->and($r->commentaire)->toBe('Avec les enfants')
        ->and($resultat['structuredContent'])->toMatchArray(['reservation_id' => $r->id, 'creneaux' => ['2026-11-14-N', '2026-11-15-J']])
        ->and(DB::table('emails_sortants')->where('gabarit', 'reservation.confirmation')->count())->toBe(1);
});

it('US-1004-P2 modifie et annule seulement les réservations à venir du membre', function () {
    $r = reserverPour($this->alice, ['2026-11-14-N'], 'ouverte', ['nombre_personnes' => 2, 'commentaire' => 'Garder']);

    $modifiee = outilMcp($this->jeton, 'modifier', ['reservation_id' => $r->id, 'nombre_personnes' => 5, 'creneaux' => ['2026-11-14-N', '2026-11-15-J']]);
    expect($modifiee['isError'])->toBeFalse();
    $r->refresh()->load('creneaux');
    expect($r->nombre_personnes)->toBe(5)->and($r->commentaire)->toBe('Garder')->and($r->creneaux)->toHaveCount(2);

    expect(outilMcp($this->jeton, 'annuler', ['reservation_id' => $r->id])['isError'])->toBeFalse()
        ->and($r->fresh()?->annulee_le)->not->toBeNull()
        ->and(DB::table('emails_sortants')->where('gabarit', 'reservation.annulee')->count())->toBe(1);

    // Une réservation passée ne se modifie plus (US-305) : même refus que sur le site.
    $passee = reserverPour($this->alice, ['2026-11-05-N']);
    $this->travelTo('2026-11-20 10:00:00');
    $jeton = connecterAssistant($this->alice, reservation: true)['access_token'];
    $refus = outilMcp($jeton, 'annuler', ['reservation_id' => $passee->id]);
    expect($refus['isError'])->toBeTrue()->and($passee->fresh()?->annulee_le)->toBeNull();
});

it('US-1004-P3 déclare les trois outils comme modifiant des données', function () {
    $outils = collect(appelMcp($this->jeton, 'tools/list')->json('result.tools'))->keyBy('name');

    expect($outils['reserver']['annotations']['readOnlyHint'])->toBeFalse()
        ->and($outils['modifier']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => true])
        ->and($outils['annuler']['annotations'])->toMatchArray(['readOnlyHint' => false, 'destructiveHint' => true])
        ->and($outils['calendrier']['annotations']['readOnlyHint'])->toBeTrue();
});

it('US-1004-P4 précise au journal que l\'action vient d\'un assistant et lequel', function () {
    outilMcp($this->jeton, 'reserver', ['creneaux' => ['2026-11-14-N'], 'mode' => 'exclusive', 'nombre_personnes' => 2]);

    expect(Journal::where('action', 'reservation.creee')->sole()->details['via'] ?? null)->toBe('assistant IA : Claude (claude.ai)');
    // Une réservation faite sur le site ne porte pas cette mention.
    $this->actingAs($this->bruno)->post('/reservations', [
        'debut' => '2026-11-20', 'fin' => '2026-11-20', 'creneaux' => ['2026-11-20-N'], 'mode' => 'ouverte', 'nombre_personnes' => 1,
    ])->assertSessionHasNoErrors();
    expect(Journal::where('action', 'reservation.creee')->where('user_id', $this->bruno->id)->sole()->details)->not->toHaveKey('via');
});

it('US-1004-N1 refuse avec le message du site et n\'enregistre rien', function () {
    reserverPour($this->bruno, ['2026-11-14-N']);

    $refus = outilMcp($this->jeton, 'reserver', ['creneaux' => ['2026-11-14-N'], 'mode' => 'ouverte', 'nombre_personnes' => 2]);
    $malEcrit = outilMcp($this->jeton, 'reserver', ['creneaux' => ['14/11/2026'], 'mode' => 'ouverte', 'nombre_personnes' => 2]);

    expect($refus)->toMatchArray(['isError' => true])
        ->and($refus['content'][0]['text'])->toBe('Un de ces créneaux est déjà réservé : choisissez des créneaux libres.')
        ->and($malEcrit['isError'])->toBeTrue()
        ->and($this->alice->reservations()->count())->toBe(0);
});

it('US-1004-N2 refuse les actions sans le droit « Réserver en mon nom »', function () {
    $lecture = connecterAssistant($this->alice)['access_token'];

    $outils = collect(appelMcp($lecture, 'tools/list')->json('result.tools'))->pluck('name');
    expect($outils)->not->toContain('reserver')->not->toContain('modifier')->not->toContain('annuler');

    appelMcp($lecture, 'tools/call', ['name' => 'reserver', 'arguments' => ['creneaux' => ['2026-11-14-N'], 'mode' => 'ouverte', 'nombre_personnes' => 2]])
        ->assertForbidden()->assertJsonMissingPath('result')
        ->assertHeader('WWW-Authenticate', 'Bearer error="insufficient_scope", scope="lecture reservation", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');
    expect(Reservation::count())->toBe(0);
});

it('US-1004-N3 n\'agit jamais sur la réservation d\'un autre membre, même pour un admin', function () {
    $admin = User::factory()->admin()->create();
    $jetonAdmin = connecterAssistant($admin, reservation: true)['access_token'];
    $deBruno = reserverPour($this->bruno, ['2026-11-14-N']);

    foreach ([$this->jeton, $jetonAdmin] as $jeton) {
        $annuler = outilMcp($jeton, 'annuler', ['reservation_id' => $deBruno->id]);
        $modifier = outilMcp($jeton, 'modifier', ['reservation_id' => $deBruno->id, 'nombre_personnes' => 9]);
        expect($annuler['isError'])->toBeTrue()->and($modifier['isError'])->toBeTrue()
            ->and($annuler['content'][0]['text'])->toStartWith('Réservation introuvable parmi les vôtres');
    }
    expect($deBruno->fresh()?->annulee_le)->toBeNull()->and($deBruno->fresh()?->nombre_personnes)->toBe(2);
});

it('US-1004-N4 refuse au-delà de 30 actions par minute, sans rien enregistrer', function () {
    config(['assistants.actions_par_minute' => 2]);
    $reserver = fn (string $cle) => appelMcp($this->jeton, 'tools/call', ['name' => 'reserver', 'arguments' => [
        'creneaux' => [$cle], 'mode' => 'ouverte', 'nombre_personnes' => 1,
    ]]);

    $reserver('2026-11-14-N')->assertOk();
    $reserver('2026-11-15-N')->assertOk();
    $reserver('2026-11-16-N')->assertStatus(429)->assertHeader('Retry-After');

    expect(Reservation::count())->toBe(2)->and(config('assistants.actions_par_minute'))->toBe(2);
    // Valeur par défaut : 30, comme les formulaires du site (throttle:30,1).
    expect(require base_path('config/assistants.php'))->toMatchArray(['actions_par_minute' => 30]);
});

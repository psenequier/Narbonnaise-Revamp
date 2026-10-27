<?php

use App\Domain\Emails\Ics;
use App\Mail\MessageSite;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-02 10:00:00');
    creerCreneaux('2026-11-01', '2026-11-30');
    $this->alice = User::factory()->create(['prenom' => 'Alice', 'email' => 'alice@example.test']);
    $this->bruno = User::factory()->create(['prenom' => 'Bruno', 'email' => 'bruno@example.test']);
});

function poster(User $membre, array $creneaux, array $autres = []): \Illuminate\Testing\TestResponse
{
    $dates = array_map(fn ($c) => substr($c, 0, 10), $creneaux);

    return test()->actingAs($membre)->post('/reservations', $autres + [
        'debut' => min($dates), 'fin' => max($dates), 'creneaux' => $creneaux, 'mode' => 'ouverte', 'nombre_personnes' => 3,
    ]);
}

/** @return \Illuminate\Support\Collection<int, MessageSite> */
function envoyes(?string $gabarit = null, ?string $a = null): \Illuminate\Support\Collection
{
    return Mail::sent(MessageSite::class, fn (MessageSite $m) => ($gabarit === null || $m->gabarit === $gabarit) && ($a === null || $m->hasTo($a) || $m->hasBcc($a)));
}

function contenuIcs(MessageSite $m): string
{
    return $m->attachments()[0]->attachWith(fn () => '', fn ($donnees) => $donnees());
}

it('US-501-P1 envoie un seul e-mail au réservant, avec dates, créneaux, type, personnes et lien vers le jour', function () {
    poster($this->alice, ['2026-11-14-N', '2026-11-15-J'])->assertSessionHasNoErrors();

    $pour = envoyes(a: 'alice@example.test');
    expect($pour)->toHaveCount(1);
    $m = $pour->first();
    expect($m->gabarit)->toBe('reservation.confirmation');
    $html = $m->render();
    expect($html)->toContain('sam. 14/11 nuit')->toContain('dim. 15/11 jour')
        ->toContain('Ouverte')->toContain('Personnes : 3')
        ->toContain(route('jour', '2026-11-14'));
});

it('US-501-P2 joint un .ics valide qui garde son identifiant et incrémente sa séquence après modification', function () {
    poster($this->alice, ['2026-11-14-N']);
    $r = Reservation::sole();
    $avant = contenuIcs(envoyes('reservation.confirmation')->sole());

    $this->actingAs($this->alice)->put("/reservations/{$r->id}", [
        'debut' => '2026-11-14', 'fin' => '2026-11-15', 'creneaux' => ['2026-11-14-N', '2026-11-15-J'], 'mode' => 'ouverte', 'nombre_personnes' => 3,
    ])->assertSessionHasNoErrors();
    $apres = contenuIcs(envoyes('reservation.modifiee')->sole());

    preg_match('/^UID:(.+)\r$/m', $avant, $uid1);
    preg_match('/^UID:(.+)\r$/m', $apres, $uid2);
    expect($uid1[1])->toBe($uid2[1])->toContain('reservation-'.$r->id)
        ->and($avant)->toContain("SEQUENCE:0\r\n")->toContain("DTSTART;VALUE=DATE:20261114\r\n")->toContain("DTEND;VALUE=DATE:20261116\r\n")
        ->and($apres)->toContain("SEQUENCE:1\r\n")->toContain("DTEND;VALUE=DATE:20261116\r\n")
        ->and($apres)->toStartWith('BEGIN:VCALENDAR')->toContain('BEGIN:VEVENT')->toContain('END:VEVENT');
});

it('US-501-N1 n\'envoie aucun e-mail pour une réservation refusée', function () {
    poster($this->alice, ['2026-11-01-N'])->assertSessionHasErrors('reservation');

    Mail::assertNothingSent();
    expect(DB::table('emails_sortants')->count())->toBe(0);
});

it('US-501-N2 affiche échappé un commentaire contenant du HTML', function () {
    poster($this->alice, ['2026-11-14-N'], ['commentaire' => '<script>alert(1)</script>']);

    $html = envoyes('reservation.confirmation')->sole()->render();
    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')->not->toContain('<script>alert(1)');
});

it('US-501-N3 ne contient ni l\'adresse des autres participants, ni lien utilisable sans connexion', function () {
    poster($this->bruno, ['2026-11-14-N']);
    poster($this->alice, ['2026-11-14-N']);

    foreach (Mail::sent(MessageSite::class) as $m) {
        $html = $m->render();
        $autres = array_diff(['alice@example.test', 'bruno@example.test'], array_column($m->to, 'address'));
        foreach ($autres as $adresse) {
            expect($html)->not->toContain($adresse);
        }
        preg_match_all('/href="([^"]+)"/', $html, $liens);
        foreach ($liens[1] as $lien) {
            expect($lien)->toStartWith(route('jour', '2026-11-14'))->not->toContain('signature')->not->toContain('token');
        }
    }
});

it('US-502-P1 prévient individuellement A quand B se joint, modifie puis annule', function () {
    poster($this->alice, ['2026-11-14-N']);
    poster($this->bruno, ['2026-11-14-N']);
    $r = Reservation::where('user_id', $this->bruno->id)->sole();
    $this->actingAs($this->bruno)->put("/reservations/{$r->id}", [
        'debut' => '2026-11-14', 'fin' => '2026-11-14', 'creneaux' => ['2026-11-14-N'], 'mode' => 'ouverte', 'nombre_personnes' => 5,
    ]);
    $this->actingAs($this->bruno)->delete("/reservations/{$r->id}");

    $mouvements = envoyes('reservation.mouvement', 'alice@example.test');
    expect($mouvements)->toHaveCount(3)
        ->and($mouvements->map(fn ($m) => $m->donnees['action'])->values()->all())->toBe(['joint', 'modifié', 'annulé'])
        ->and($mouvements->every(fn ($m) => count($m->to) === 1 && $m->bcc === []))->toBeTrue();
});

it('US-502-N1 ne prévient pas individuellement un membre sans réservation sur ces créneaux', function () {
    $carla = User::factory()->create(['email' => 'carla@example.test']);
    poster($carla, ['2026-11-20-N']);
    poster($this->alice, ['2026-11-14-N']);
    poster($this->bruno, ['2026-11-14-N']);

    expect(envoyes('reservation.mouvement', 'carla@example.test'))->toHaveCount(0)
        ->and(envoyes('reservation.mouvement', 'alice@example.test'))->toHaveCount(1);
});

it('US-502-N2 ne laisse aucune préférence forgée désactiver le message individuel', function () {
    poster($this->alice, ['2026-11-14-N']);
    $this->actingAs($this->alice)->put('/profil/preferences', [
        'recoit_emails_autres' => '0', 'recoit_annonces_attribution' => '0', 'recoit_emails_mes_creneaux' => '0',
    ]);

    poster($this->bruno, ['2026-11-14-N']);

    expect(envoyes('reservation.mouvement', 'alice@example.test'))->toHaveCount(1);
});

it('US-502-N3 n\'envoie à l\'auteur que sa confirmation, sans doublon', function () {
    poster($this->alice, ['2026-11-14-N']);
    poster($this->bruno, ['2026-11-14-N']);

    $pourBruno = envoyes(a: 'bruno@example.test');
    expect($pourBruno->map->gabarit->all())->toBe(['reservation.confirmation']);
});

it('US-503-P1 envoie une seule information en copie cachée aux membres qui la souhaitent, hors réservant et inscrits', function () {
    poster($this->bruno, ['2026-11-14-N']);
    Mail::fake();
    $curieux = User::factory()->create(['email' => 'curieux@example.test']);

    poster($this->alice, ['2026-11-14-N']);

    $info = envoyes('reservation.information');
    expect($info)->toHaveCount(1);
    $m = $info->sole();
    expect($m->hasBcc('curieux@example.test'))->toBeTrue()
        ->and($m->hasBcc('alice@example.test'))->toBeFalse()
        ->and($m->hasBcc('bruno@example.test'))->toBeFalse();
});

it('US-503-P2 garde l\'information en file au-delà de 150 envois dans l\'heure, puis l\'envoie à la relance', function () {
    DB::table('emails_sortants')->insert([
        'gabarit' => 'reservation.information', 'priorite' => 2, 'destinataires' => '{"a":[],"cci":[]}',
        'nombre_destinataires' => 149, 'donnees' => '{}', 'tentatives' => 1,
        'envoye_le' => now()->subMinutes(30), 'created_at' => now(), 'updated_at' => now(),
    ]);

    poster($this->alice, ['2026-11-14-N']);

    expect(envoyes('reservation.confirmation'))->toHaveCount(1)
        ->and(envoyes('reservation.information'))->toHaveCount(0)
        ->and(DB::table('emails_sortants')->where('gabarit', 'reservation.information')->whereNull('envoye_le')->count())->toBe(1);

    $this->travel(31)->minutes();
    $this->artisan('emails:envoyer')->assertSuccessful();

    expect(envoyes('reservation.information'))->toHaveCount(1);
});

it('US-503-N1 ne montre aucune adresse de membre dans À ni Cc du message groupé', function () {
    poster($this->alice, ['2026-11-14-N']);

    $m = envoyes('reservation.information')->sole();
    expect(array_column($m->to, 'address'))->toBe([config('mail.from.address')])
        ->and($m->cc)->toBe([]);
});

it('US-503-N2 exclut les membres qui ont désactivé la préférence et les comptes désactivés', function () {
    $sansInfo = User::factory()->create(['email' => 'sans-info@example.test']);
    $sansInfo->forceFill(['recoit_emails_autres' => false])->save();
    $desactive = User::factory()->create(['email' => 'desactive@example.test']);
    $desactive->forceFill(['actif' => false])->save();
    $invite = User::factory()->invite()->create(['email' => 'invite@example.test']);

    poster($this->alice, ['2026-11-14-N']);

    $m = envoyes('reservation.information')->sole();
    expect($m->hasBcc('bruno@example.test'))->toBeTrue()
        ->and($m->hasBcc('sans-info@example.test'))->toBeFalse()
        ->and($m->hasBcc('desactive@example.test'))->toBeFalse()
        ->and($m->hasBcc('invite@example.test'))->toBeFalse();
});

it('US-503-N3 ne dépasse jamais 150 envois par heure, même avec 10 réservations dans la même minute', function () {
    $membres = User::factory()->count(18)->create();
    $tous = User::all();

    foreach ($tous->take(10)->values() as $i => $membre) {
        poster($membre, [sprintf('2026-11-%02d-N', 10 + $i)])->assertSessionHasNoErrors();
    }

    $destinataires = Mail::sent(MessageSite::class)->sum(fn ($m) => count($m->to) + count($m->bcc));
    expect($destinataires)->toBeLessThanOrEqual(150)
        ->and(app(\App\Domain\Emails\BoiteEnvoi::class)->envoyesDerniereHeure())->toBeLessThanOrEqual(150)
        ->and(DB::table('emails_sortants')->whereNull('envoye_le')->count())->toBeGreaterThan(0)
        ->and(envoyes('reservation.confirmation'))->toHaveCount(10);
});

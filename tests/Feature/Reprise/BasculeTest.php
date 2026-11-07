<?php

use App\Mail\MessageSite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-20 10:00:00');
    $this->actif = User::factory()->create(['email' => 'actif@example.test']);
    $this->ancien = User::factory()->invite()->create(['email' => 'ancien-md5@example.test', 'prenom' => 'Ancien']);
    $this->ancien->forceFill(['a_reinitialiser' => true])->save();
    $this->desactive = User::factory()->create(['email' => 'parti@example.test']);
    $this->desactive->forceFill(['actif' => false])->save();
});

function pour(string $adresse): \Illuminate\Support\Collection
{
    return Mail::sent(MessageSite::class, fn ($m) => $m->hasTo($adresse) || $m->hasBcc($adresse));
}

it('US-806-P1 annonce la bascule à tous les comptes actifs, avec la date et la durée, quand l\'admin le décide', function () {
    $this->artisan('bascule:annoncer 2026-12-12 --duree="une nuit, de 22 h à 6 h"')->assertSuccessful();

    $annonce = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'bascule.annonce')->sole();
    expect($annonce->hasBcc('actif@example.test'))->toBeTrue()
        ->and($annonce->hasBcc('ancien-md5@example.test'))->toBeTrue()
        ->and($annonce->render())->toContain('samedi 12 décembre 2026')->toContain('une nuit, de 22 h à 6 h')->toContain('adresse e-mail');

    // Pascal choisit le moment de l'annonce : moins de 7 jours avant est permis, une date passée non.
    Mail::fake();
    $this->artisan('bascule:annoncer 2026-11-23')->assertSuccessful();
    Mail::fake();
    $this->artisan('bascule:annoncer 2026-11-20')->assertFailed();
    Mail::assertNothingSent();
});

it('termine l\'annonce par un hommage à l\'ancien site, chiffres calculés depuis la reprise', function () {
    config(['chamrousse.grr_mise_a_jour_majeure' => '2019']);
    creerCreneaux('2014-02-08', '2014-02-10');
    creerCreneaux('2026-12-24', '2026-12-26');
    $reprise = function (User $membre, array $cles, ?int $grrId, bool $annulee = false) {
        $id = DB::table('reservations')->insertGetId(['user_id' => $membre->id, 'mode' => 'ouverte', 'grr_id' => $grrId,
            'annulee_le' => $annulee ? now() : null, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($cles as $cle) {
            $creneau = DB::table('creneaux')->where('date', substr($cle, 0, 10))->where('type', substr($cle, -1))->value('id');
            DB::table('reservation_creneau')->insert(['reservation_id' => $id, 'creneau_id' => $creneau]);
        }
    };
    $reprise($this->actif, ['2014-02-08J', '2014-02-08N', '2014-02-09J'], 1);
    $reprise($this->ancien, ['2026-12-24N', '2026-12-25J'], 2);
    $reprise($this->actif, ['2026-12-26J'], 3, annulee: true);
    $reprise($this->actif, ['2014-02-10J'], null); // réservation du nouveau site : pas comptée

    $this->artisan('bascule:annoncer 2026-12-12')->assertSuccessful();

    $texte = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'bascule.annonce')->sole()->render();
    expect($texte)->toContain('depuis <strong>février 2014</strong>, soit plus de 12 ans')
        ->toContain('2 réservations')->toContain('5 créneaux réservés')->toContain('dont 2 nuits')
        ->toContain('pour 2 membres')->toContain('mise à jour majeure (en 2019)')
        ->toContain('href="https://www.youtube.com/shorts/ddW4i7Oftkc"')->toContain('le Glaude');
});

it('garde l\'hommage sans chiffres tant que rien n\'est repris', function () {
    $this->artisan('bascule:annoncer 2026-12-12')->assertSuccessful();

    $texte = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'bascule.annonce')->sole()->render();
    expect($texte)->toContain('mise à jour majeure,')->not->toContain('créneaux réservés')->toContain('le Glaude');
});

it('montre le texte de l\'annonce sur l\'écran admin avant l\'envoi', function () {
    $this->actingAs(adminAvec2fa())->get('/admin/reprise')->assertOk()
        ->assertSee('Texte de l\'annonce')->assertSee('Le site de réservation de l\'appartement change')
        ->assertSee('[date de la bascule]')->assertSee('le Glaude');
    Mail::assertNothingSent();
});

it('US-806-P2 envoie le jour J un lien valable 7 jours aux comptes à réinitialiser, la nouvelle adresse seule aux autres', function () {
    $this->artisan('bascule:annoncer --mise-en-service')->assertSuccessful();

    $lien = pour('ancien-md5@example.test')->sole();
    expect($lien->gabarit)->toBe('bascule.reinitialiser')->and($lien->hasTo('ancien-md5@example.test'))->toBeTrue();
    $adresse = pour('actif@example.test')->sole();
    expect($adresse->gabarit)->toBe('bascule.nouveau_site')->and($adresse->render())->toContain(route('login'));

    $url = $lien->donnees['lien'];
    $this->get($url)->assertOk();
    $this->travel(7)->days();
    $this->travel(1)->minutes();
    $this->get($url)->assertStatus(410);
});

it('active le compte à réinitialiser depuis le lien, qui ne l\'est plus ensuite', function () {
    simulerFuites();
    $this->artisan('bascule:annoncer --mise-en-service');
    $url = pour('ancien-md5@example.test')->sole()->donnees['lien'];

    $this->post($url, ['password' => MDP_NOUVEAU, 'password_confirmation' => MDP_NOUVEAU])->assertRedirect('/');

    expect($this->ancien->fresh()->a_reinitialiser)->toBeFalse()->and($this->ancien->fresh()->statut())->toBe('Actif');
});

it('US-806-N1 n\'envoie rien aux comptes désactivés', function () {
    $this->artisan('bascule:annoncer 2026-12-12');
    $this->artisan('bascule:annoncer --mise-en-service');

    expect(pour('parti@example.test'))->toHaveCount(0);
});

it('US-806-N2 met les destinataires en copie cachée, un seul e-mail par annonce et par personne', function () {
    $this->artisan('bascule:annoncer 2026-12-12');
    $this->artisan('bascule:annoncer --mise-en-service');

    foreach (Mail::sent(MessageSite::class, fn ($m) => in_array($m->gabarit, ['bascule.annonce', 'bascule.nouveau_site'], true)) as $m) {
        expect(array_column($m->to, 'address'))->toBe([config('mail.from.address')]);
    }
    foreach (['actif@example.test', 'ancien-md5@example.test'] as $adresse) {
        $parAnnonce = pour($adresse)->groupBy(fn ($m) => $m->gabarit === 'bascule.annonce' ? 'annonce' : 'jour J');
        expect($parAnnonce['annonce'])->toHaveCount(1)->and($parAnnonce['jour J'])->toHaveCount(1);
    }
});

it('envoie les annonces depuis l\'écran admin, la mise en service une seule fois', function () {
    $admin = adminAvec2fa();

    $this->actingAs($admin)->post('/admin/reprise/annonce', ['date' => '2026-12-12', 'duree' => 'une nuit'])->assertSessionHasNoErrors();
    $this->actingAs($admin)->post('/admin/reprise/annonce', ['date' => '2026-11-20', 'duree' => 'une nuit'])->assertSessionHasErrors('date');
    $this->actingAs($admin)->post('/admin/reprise/mise-en-service')->assertRedirect();
    $this->actingAs($admin)->post('/admin/reprise/mise-en-service')->assertStatus(409);
    $this->actingAs($this->actif)->post('/admin/reprise/mise-en-service')->assertForbidden();

    expect(Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'bascule.annonce'))->toHaveCount(1)
        ->and(pour('ancien-md5@example.test')->where('gabarit', 'bascule.reinitialiser'))->toHaveCount(1);
});

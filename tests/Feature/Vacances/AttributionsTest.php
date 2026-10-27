<?php

use App\Domain\Calendrier\EtatCreneau;
use App\Domain\Calendrier\Occupation;
use App\Mail\MessageSite;
use App\Models\Attribution;
use App\Models\Journal;
use App\Models\Lignee;
use App\Models\Plage;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-02 10:00:00');
    ouvrirAnnee2026();
    Mail::fake();
    $this->a = Lignee::create(['nom' => 'Lignée Bleue', 'couleur' => '#2F6FB0']);
    $this->b = Lignee::create(['nom' => 'Lignée Rouge', 'couleur' => '#B5651D']);
    $this->admin = adminAvec2fa(['prenom' => 'Ada', 'nom' => 'Administratrice']);
    $this->membre = User::factory()->create(['prenom' => 'Marc', 'nom' => 'Membre', 'email' => 'membre@example.test']);
});

function plage(int $lignee, string $debut, string $fin, ?string $periode = 'noel'): array
{
    return [
        'periode_id' => periode($periode)->id, 'lignee_id' => $lignee,
        'debut' => substr($debut, 0, 10), 'debut_type' => substr($debut, -1),
        'fin' => substr($fin, 0, 10), 'fin_type' => substr($fin, -1),
    ];
}

function etats(string $du, string $au): array
{
    return collect(app(Occupation::class)->entre(new DateTimeImmutable($du), new DateTimeImmutable($au)))
        ->map(fn ($s) => $s->etat === EtatCreneau::Attribue ? 'attribue:'.$s->lignee : $s->etat->value)->all();
}

it('US-403-P1 attribue les 16 créneaux de la nuit du 18 décembre à la journée du 26 décembre', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-26-J'))
        ->assertSessionHasNoErrors()->assertRedirect();

    expect(Attribution::where('lignee_id', $this->a->id)->count())->toBe(16);
    $e = etats('2026-12-18', '2026-12-27');
    expect($e['2026-12-18-N'])->toBe('attribue:Lignée Bleue')
        ->and($e['2026-12-26-J'])->toBe('attribue:Lignée Bleue')
        ->and($e['2026-12-26-N'])->toBe('attente')
        ->and($e['2026-12-18-J'])->toBe('libre');
    $this->actingAs($this->membre)->get('/calendrier/2026-12')->assertSee('Lignée Bleue');
});

it('US-403-P2 accepte deux plages contiguës pour deux lignées différentes', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-26-J'))->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->b->id, '2026-12-26-N', '2027-01-03-J'))->assertSessionHasNoErrors();

    expect(Attribution::where('lignee_id', $this->a->id)->count())->toBe(16)
        ->and(Attribution::where('lignee_id', $this->b->id)->count())->toBe(16);
});

it('US-403-N1 refuse une plage qui chevauche une plage existante', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-26-J'));

    $this->actingAs($this->admin)->post('/admin/plages', plage($this->b->id, '2026-12-26-J', '2026-12-28-J'))
        ->assertSessionHasErrors(['plage' => 'Cette plage chevauche une plage déjà attribuée.']);
    expect(Attribution::where('lignee_id', $this->b->id)->count())->toBe(0);
});

it('US-403-N2 refuse une plage qui déborde de la période de vacances', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-17-N', '2026-12-20-J'))
        ->assertSessionHasErrors('plage');
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2027-01-02-N', '2027-01-03-N'))
        ->assertSessionHasErrors('plage');
    expect(Attribution::count())->toBe(0);
});

it('US-403-N3 refuse une lignée inconnue', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage(999999, '2026-12-18-N', '2026-12-20-J'))
        ->assertSessionHasErrors(['plage' => 'Lignée inconnue.']);
    expect(Attribution::count())->toBe(0);
});

it('US-403-N4 renvoie 403 à un membre non admin', function () {
    $this->actingAs($this->membre)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-20-J'))->assertForbidden();
    $this->actingAs($this->membre)->get('/admin/vacances')->assertForbidden();
    expect(Attribution::count())->toBe(0);
});

it('US-403-N5 garde bloqués les créneaux restés sans lignée', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-26-J'));

    $this->actingAs($this->membre)->post('/reservations', [
        'debut' => '2026-12-27', 'fin' => '2026-12-27', 'creneaux' => ['2026-12-27-N'], 'mode' => 'ouverte', 'nombre_personnes' => 2,
    ])->assertSessionHasErrors('reservation');
    expect(Reservation::count())->toBe(0);
});

it('US-404-P1 repasse en attente les créneaux d\'une plage retirée, trace et annonce', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-26-J'));
    Mail::fake();

    $this->actingAs($this->admin)->delete('/admin/plages/'.Plage::sole()->id)->assertRedirect();

    expect(Attribution::count())->toBe(0)
        ->and(array_unique(array_values(array_diff_key(etats('2026-12-19', '2026-12-25'), []))))->toBe(['attente'])
        ->and(Journal::where('action', 'plage.retiree')->exists())->toBeTrue();
    Mail::assertSent(MessageSite::class, fn ($m) => $m->gabarit === 'vacances.attributions' && $m->donnees['evenement'] === 'retrait');
});

it('US-404-P2 ne met à jour que les créneaux concernés par la modification', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-26-J'));
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->b->id, '2026-12-28-N', '2026-12-30-J'));

    $this->actingAs($this->admin)->put('/admin/plages/'.Plage::where('lignee_id', $this->a->id)->sole()->id, plage($this->b->id, '2026-12-20-N', '2026-12-24-J'))
        ->assertSessionHasNoErrors();

    $e = etats('2026-12-18', '2026-12-31');
    expect($e['2026-12-20-J'])->toBe('attente')
        ->and($e['2026-12-20-N'])->toBe('attribue:Lignée Rouge')
        ->and($e['2026-12-24-J'])->toBe('attribue:Lignée Rouge')
        ->and($e['2026-12-24-N'])->toBe('attente')
        ->and($e['2026-12-29-J'])->toBe('attribue:Lignée Rouge')
        ->and(Attribution::count())->toBe(8 + 4);
});

it('US-404-N1 ne laisse aucun créneau libre après un retrait : réservation refusée', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-26-J'));
    $this->actingAs($this->admin)->delete('/admin/plages/'.Plage::sole()->id);

    expect(collect(etats('2026-12-19', '2026-12-25'))->contains('libre'))->toBeFalse();
    $this->actingAs($this->membre)->post('/reservations', [
        'debut' => '2026-12-20', 'fin' => '2026-12-20', 'creneaux' => ['2026-12-20-N'], 'mode' => 'ouverte', 'nombre_personnes' => 2,
    ])->assertSessionHasErrors('reservation');
});

it('US-404-N2 refuse une modification qui créerait un chevauchement et garde la plage d\'origine', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-20-J'));
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->b->id, '2026-12-22-N', '2026-12-24-J'));
    $plageA = Plage::where('lignee_id', $this->a->id)->sole();

    $this->actingAs($this->admin)->put('/admin/plages/'.$plageA->id, plage($this->a->id, '2026-12-18-N', '2026-12-23-J'))
        ->assertSessionHasErrors('plage');

    expect($plageA->fresh()->fin->format('Y-m-d'))->toBe('2026-12-20')
        ->and(Attribution::where('plage_id', $plageA->id)->count())->toBe(4);
});

it('US-504-P1 envoie aux membres qui le souhaitent un récapitulatif de chaque plage et de sa lignée', function () {
    $sans = User::factory()->create(['email' => 'sans-annonce@example.test']);
    $sans->forceFill(['recoit_annonces_attribution' => false])->save();

    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-26-J'));
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->b->id, '2027-02-12-N', '2027-02-20-J', 'hiver'));

    $recap = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'vacances.attributions')->last();
    expect($recap->hasBcc('membre@example.test'))->toBeTrue()
        ->and($recap->hasBcc('sans-annonce@example.test'))->toBeFalse();
    $html = $recap->render();
    expect($html)->toContain('Lignée Bleue')->toContain('Lignée Rouge')->toContain('ven. 18/12 nuit')->toContain('Février');
});

it('US-504-N1 n\'envoie aucun e-mail pour une attribution refusée', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-20-J'));
    Mail::fake();

    $this->actingAs($this->admin)->post('/admin/plages', plage($this->b->id, '2026-12-19-N', '2026-12-21-J'))->assertSessionHasErrors('plage');

    Mail::assertNothingSent();
});

it('US-504-N2 ne met aucun nom de personne dans le récapitulatif', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-26-J'));

    $html = Mail::sent(MessageSite::class, fn ($m) => $m->gabarit === 'vacances.attributions')->sole()->render();
    expect($html)->not->toContain('Ada')->not->toContain('Administratrice')->not->toContain('Marc')->not->toContain('Membre');
});

it('affiche l\'écran Vacances à un admin', function () {
    $this->actingAs($this->admin)->post('/admin/plages', plage($this->a->id, '2026-12-18-N', '2026-12-26-J'));

    $this->actingAs($this->admin)->get('/admin/vacances')->assertOk()
        ->assertSee('Noël 2026-2027')->assertSee('Lignée Bleue')->assertSee('data-plage', false);
});

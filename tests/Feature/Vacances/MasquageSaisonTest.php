<?php

use App\Domain\Vacances\PeriodesScolaires;
use App\Models\AnneeScolaire;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/*
 * Décision du 15 nov. 2026 : un admin masque une saison ouverte pas encore commencée
 * (ex. 2027-2028 ouverte d'office par le calendrier officiel), puis la rouvre en saisissant
 * obligatoirement les dates de Noël et de février, dont les créneaux sont en attente d'attribution.
 */

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-02 10:00:00');
    $this->admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
    $this->membre = User::factory()->create(['email' => 'membre@example.test']);
    $encours = AnneeScolaire::query()->create(['libelle' => '2026-2027', 'debut' => '2026-09-01', 'fin' => '2027-08-31']);
    $encours->forceFill(['ouverte' => true])->save();
});

/** 2027-2028 ouverte comme par le calendrier officiel (pas créée à la main, dates non corrigées). */
function saisonSuivanteOfficielle(\Tests\TestCase $test, User $admin): AnneeScolaire
{
    $test->actingAs($admin)->post('/admin/vacances/saisir', [
        'annee' => '2027-2028',
        'noel_premiere_nuit' => '2027-12-17', 'noel_dernier_jour' => '2028-01-02',
        'hiver_premiere_nuit' => '2028-02-11', 'hiver_dernier_jour' => '2028-02-27',
    ])->assertSessionHasNoErrors();
    $annee = AnneeScolaire::where('libelle', '2027-2028')->sole();
    $annee->forceFill(['creee_a_la_main' => false])->save();
    DB::table('periodes_vacances')->where('annee_scolaire_id', $annee->id)->update(['manuelle' => false]);

    return $annee->refresh();
}

function creneauxDe2027(): int
{
    return DB::table('creneaux')->whereBetween('date', ['2027-09-01', '2028-08-31'])->count();
}

it('masque une saison ouverte pas encore commencée, même officielle, après confirmation', function () {
    $annee = saisonSuivanteOfficielle($this, $this->admin);
    expect(creneauxDe2027())->toBe(732);

    $this->actingAs($this->admin)->get('/admin/vacances?annee=2027-2028')->assertOk()
        ->assertSee('Masquer la saison 2027-2028')->assertSee('name="confirmation_masquage"', false);

    // Sans la case cochée : rien ne change.
    $this->actingAs($this->admin)->post("/admin/vacances/annees/{$annee->id}/masquer")->assertSessionHasErrors('confirmation_masquage');
    expect($annee->refresh()->ouverte)->toBeTrue();

    $this->actingAs($this->admin)->post("/admin/vacances/annees/{$annee->id}/masquer", ['confirmation_masquage' => '1'])
        ->assertRedirect('/admin/vacances?annee=2027-2028')->assertSessionHasNoErrors();

    $annee->refresh();
    expect($annee->ouverte)->toBeFalse()
        ->and($annee->masquee)->toBeTrue()
        ->and(creneauxDe2027())->toBe(0)
        // Les vacances restent connues : elles sont proposées pour la réouverture.
        ->and($annee->periodes()->count())->toBe(2)
        ->and(Journal::where('action', 'annee.masquee')->sole()->details['libelle'])->toBe('2027-2028');

    // Plus réservable : le calendrier des membres est refermé sur ces dates, la saison en cours est intacte.
    $this->actingAs($this->membre)->get('/calendrier/2027-11')->assertOk()->assertSee('dès que les dates');
    expect(AnneeScolaire::where('libelle', '2026-2027')->value('ouverte'))->toBeTruthy();

    // L'écran propose de la rouvrir, dates connues pré-remplies.
    $this->actingAs($this->admin)->get('/admin/vacances?annee=2027-2028')->assertOk()
        ->assertSee('(masquée)')->assertSee('Ouvrir la saison 2027-2028 à la main', false)
        ->assertSee('value="2027-12-17"', false)->assertSee('value="2028-02-27"', false);
});

it('ne rouvre pas d\'elle-même une saison masquée à la relecture du calendrier officiel', function () {
    $annee = saisonSuivanteOfficielle($this, $this->admin);
    $this->actingAs($this->admin)->post("/admin/vacances/annees/{$annee->id}/masquer", ['confirmation_masquage' => '1'])->assertSessionHasNoErrors();

    expect(app(PeriodesScolaires::class)->ouvrirSiPossible($annee->refresh()))->toBeFalse()
        ->and($annee->refresh()->ouverte)->toBeFalse()
        ->and(creneauxDe2027())->toBe(0);
});

it('rouvre une saison masquée avec les dates de Noël et de février obligatoires, créneaux en attente d\'attribution', function () {
    $annee = saisonSuivanteOfficielle($this, $this->admin);
    $this->actingAs($this->admin)->post("/admin/vacances/annees/{$annee->id}/masquer", ['confirmation_masquage' => '1'])->assertSessionHasNoErrors();

    // Une date manquante : refus, la saison reste masquée.
    $this->actingAs($this->admin)->post('/admin/vacances/saisir', [
        'annee' => '2027-2028',
        'noel_premiere_nuit' => '2027-12-18', 'noel_dernier_jour' => '2028-01-03',
        'hiver_premiere_nuit' => '2028-02-12',
    ])->assertSessionHasErrors('hiver_dernier_jour');
    expect($annee->refresh()->ouverte)->toBeFalse();

    $this->actingAs($this->admin)->post('/admin/vacances/saisir', [
        'annee' => '2027-2028',
        'noel_premiere_nuit' => '2027-12-18', 'noel_dernier_jour' => '2028-01-03',
        'hiver_premiere_nuit' => '2028-02-12', 'hiver_dernier_jour' => '2028-02-28',
    ])->assertRedirect('/admin/vacances?annee=2027-2028')->assertSessionHasNoErrors();

    $annee->refresh();
    $noel = $annee->periodes()->where('nom', 'noel')->sole();
    expect($annee->ouverte)->toBeTrue()
        ->and($annee->masquee)->toBeFalse()
        ->and(creneauxDe2027())->toBe(732)
        ->and($noel->premiere_nuit->format('Y-m-d'))->toBe('2027-12-18')
        ->and($noel->manuelle)->toBeTrue()
        // Noël : du 18 déc. (nuit) au 3 janv. (jour) = 33 créneaux, tous en attente (aucune lignée).
        ->and(DB::table('creneaux')->where('periode_id', $noel->id)->count())->toBe(33)
        ->and(DB::table('attributions')->count())->toBe(0);

    $this->actingAs($this->admin)->get('/admin/vacances?annee=2027-2028')->assertOk()->assertSee('En attente');
});

it('refuse de masquer une saison avec une réservation active, ou une saison commencée', function () {
    $annee = saisonSuivanteOfficielle($this, $this->admin);
    reserverPour($this->membre, ['2027-10-09N', '2027-10-10J']);

    $this->actingAs($this->admin)->get('/admin/vacances?annee=2027-2028')->assertOk()
        ->assertSee('Masquage impossible. 1 réservation : annulez-les ou retirez-les d\'abord.', false)
        ->assertDontSee('Masquer la saison 2027-2028');
    $this->actingAs($this->admin)->post("/admin/vacances/annees/{$annee->id}/masquer", ['confirmation_masquage' => '1'])
        ->assertSessionHasErrors('masquage');
    expect($annee->refresh()->ouverte)->toBeTrue();

    $encours = AnneeScolaire::where('libelle', '2026-2027')->sole();
    $this->actingAs($this->admin)->get('/admin/vacances?annee=2026-2027')->assertOk()->assertDontSee('Masquer la saison');
    $this->actingAs($this->admin)->post("/admin/vacances/annees/{$encours->id}/masquer", ['confirmation_masquage' => '1'])
        ->assertSessionHasErrors(['masquage' => 'Saison commencée ou passée : elle ne se masque pas.']);
});

it('réserve le masquage aux admins', function () {
    $annee = saisonSuivanteOfficielle($this, $this->admin);

    $this->actingAs($this->membre)->post("/admin/vacances/annees/{$annee->id}/masquer", ['confirmation_masquage' => '1'])->assertForbidden();
    expect($annee->refresh()->ouverte)->toBeTrue();
});

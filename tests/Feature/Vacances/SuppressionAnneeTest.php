<?php

use App\Models\AnneeScolaire;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-02 10:00:00');
    $this->admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
    $this->membre = User::factory()->create(['email' => 'membre@example.test']);
    $encours = AnneeScolaire::query()->create(['libelle' => '2026-2027', 'debut' => '2026-09-01', 'fin' => '2027-08-31']);
    $encours->forceFill(['ouverte' => true])->save();
});

/** Saisit à la main l'année proposée (2027-2028) : créée à la main et ouverte. */
function saisirAnneeSuivante(\Tests\TestCase $test, User $admin): AnneeScolaire
{
    $test->actingAs($admin)->post('/admin/vacances/saisir', [
        'annee' => '2027-2028',
        'noel_premiere_nuit' => '2027-12-17', 'noel_dernier_jour' => '2028-01-02',
        'hiver_premiere_nuit' => '2028-02-11', 'hiver_dernier_jour' => '2028-02-27',
    ])->assertSessionHasNoErrors();

    return AnneeScolaire::where('libelle', '2027-2028')->sole();
}

it('US-409-P1 supprime une saison créée à la main, pas commencée et vide, après confirmation', function () {
    $annee = saisirAnneeSuivante($this, $this->admin);
    expect($annee->creee_a_la_main)->toBeTrue()
        ->and(DB::table('creneaux')->whereBetween('date', ['2027-09-01', '2028-08-31'])->count())->toBe(732);

    $this->actingAs($this->admin)->get('/admin/vacances?annee=2027-2028')->assertOk()
        ->assertSee('Supprimer la saison 2027-2028')->assertSee('name="confirmation"', false);

    // Sans la case cochée : rien n'est supprimé.
    $this->actingAs($this->admin)->delete("/admin/vacances/annees/{$annee->id}")->assertSessionHasErrors('confirmation');
    expect(AnneeScolaire::whereKey($annee->id)->exists())->toBeTrue();

    $this->actingAs($this->admin)->delete("/admin/vacances/annees/{$annee->id}", ['confirmation' => '1'])
        ->assertRedirect('/admin/vacances')->assertSessionHas('status', 'Saison 2027-2028 supprimée.');

    expect(AnneeScolaire::whereKey($annee->id)->exists())->toBeFalse()
        ->and(DB::table('periodes_vacances')->where('annee_scolaire_id', $annee->id)->count())->toBe(0)
        ->and(DB::table('creneaux')->whereBetween('date', ['2027-09-01', '2028-08-31'])->count())->toBe(0)
        ->and(Journal::where('action', 'annee.supprimee')->sole()->details['libelle'])->toBe('2027-2028');
    // Le calendrier s'est refermé sur ces dates ; la saison en cours est intacte.
    $this->actingAs($this->membre)->get('/calendrier/2027-11')->assertOk()->assertSee('dès que les dates');
    expect(AnneeScolaire::where('libelle', '2026-2027')->exists())->toBeTrue();
});

it('US-409-N1 refuse la suppression avec une réservation active ou un créneau attribué, et en donne la raison', function () {
    $annee = saisirAnneeSuivante($this, $this->admin);
    reserverPour($this->membre, ['2027-10-09N', '2027-10-10J']);
    $lignee = DB::table('lignees')->insertGetId(['nom' => 'Lignée A', 'couleur' => '#2F6FB0']);
    $noel = DB::table('creneaux')->whereDate('date', '2027-12-20')->pluck('id');
    foreach ($noel as $id) {
        DB::table('attributions')->insert(['creneau_id' => $id, 'lignee_id' => $lignee, 'saisie_par' => $this->admin->id]);
    }

    $this->actingAs($this->admin)->get('/admin/vacances?annee=2027-2028')->assertOk()
        ->assertSee('1 réservation et 2 créneaux attribués : annulez-les ou retirez-les d\'abord.')
        ->assertDontSee('Supprimer la saison 2027-2028');

    $this->actingAs($this->admin)->delete("/admin/vacances/annees/{$annee->id}", ['confirmation' => '1'])
        ->assertSessionHasErrors('suppression');
    expect(AnneeScolaire::whereKey($annee->id)->exists())->toBeTrue();
});

it('US-409-P1 emporte les réservations annulées de la saison supprimée', function () {
    $annee = saisirAnneeSuivante($this, $this->admin);
    $r = reserverPour($this->membre, ['2027-10-09N']);
    app(\App\Domain\Reservations\ServiceReservation::class)->annuler($r, new DateTimeImmutable('2026-11-02 10:00:00'));

    $this->actingAs($this->admin)->delete("/admin/vacances/annees/{$annee->id}", ['confirmation' => '1'])->assertSessionHasNoErrors();
    expect(DB::table('reservations')->whereKey($r->id)->exists())->toBeFalse();
});

it('US-409-N2 ne supprime jamais une saison du calendrier officiel, ni une saison commencée', function () {
    $officielle = ouvrirAnnee2026(); // 2026-2027, d'après la source, commencée le 1er septembre
    $officielle->forceFill(['creee_a_la_main' => false])->save();

    $this->actingAs($this->admin)->get('/admin/vacances?annee=2026-2027')->assertOk()->assertDontSee('Supprimer la saison');
    $this->actingAs($this->admin)->delete("/admin/vacances/annees/{$officielle->id}", ['confirmation' => '1'])
        ->assertSessionHasErrors(['suppression' => 'Saison créée d\'après le calendrier officiel : elle ne se supprime pas, corrigez ses dates si besoin.']);

    $officielle->forceFill(['creee_a_la_main' => true])->save();
    $this->actingAs($this->admin)->get('/admin/vacances?annee=2026-2027')->assertOk()
        ->assertSee('Saison commencée ou passée : elle ne se supprime pas.');
    $this->actingAs($this->admin)->delete("/admin/vacances/annees/{$officielle->id}", ['confirmation' => '1'])
        ->assertSessionHasErrors('suppression');
    expect(AnneeScolaire::whereKey($officielle->id)->exists())->toBeTrue();
});

it('US-409-N3 réserve la suppression aux admins', function () {
    $annee = saisirAnneeSuivante($this, $this->admin);

    $this->actingAs($this->membre)->delete("/admin/vacances/annees/{$annee->id}", ['confirmation' => '1'])->assertForbidden();
    expect(AnneeScolaire::whereKey($annee->id)->exists())->toBeTrue();
});

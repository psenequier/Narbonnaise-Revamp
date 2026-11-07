<?php

use App\Mail\MessageSite;
use App\Models\AnneeScolaire;
use App\Models\PeriodeVacances;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
    $this->travelTo('2026-11-02 10:00:00');
    $this->admin = User::factory()->admin()->create(['email' => 'admin@example.test']);
    $this->membre = User::factory()->create(['email' => 'membre@example.test']);
});

/** L'année en cours, déjà ouverte : l'année proposée à la saisie est alors 2027-2028. */
function anneeEnCoursOuverte(): AnneeScolaire
{
    $annee = AnneeScolaire::query()->create(['libelle' => '2026-2027', 'debut' => '2026-09-01', 'fin' => '2027-08-31']);
    $annee->forceFill(['ouverte' => true])->save();

    return $annee;
}

function datesSaisies(array $autres = []): array
{
    return [
        'annee' => '2027-2028',
        'noel_premiere_nuit' => '2027-12-17', 'noel_dernier_jour' => '2028-01-02',
        'hiver_premiere_nuit' => '2028-02-11', 'hiver_dernier_jour' => '2028-02-27',
        ...$autres,
    ];
}

it('laisse un admin relire le calendrier scolaire sans attendre le 1er du mois, et affiche la dernière lecture', function () {
    simulerCalendrier();
    $this->actingAs($this->admin)->get('/admin/vacances')->assertSee('Pas encore lu.');

    $this->actingAs($this->admin)->from('/admin/vacances')->post('/admin/vacances/relire')
        ->assertRedirect('/admin/vacances')->assertSessionHas('status');

    expect(AnneeScolaire::where('libelle', '2026-2027')->value('ouverte'))->toBeTruthy();
    $this->actingAs($this->admin)->get('/admin/vacances')->assertSee('Dernière lecture le')->assertSee(': réussie.');
});

it('affiche une lecture en échec et propose la saisie à la main', function () {
    simulerCalendrier();
    reinitialiserHttp();
    Http::fake(fn () => Http::response(['erreur' => 'indisponible'], 500));

    $this->actingAs($this->admin)->from('/admin/vacances')->post('/admin/vacances/relire')
        ->assertRedirect('/admin/vacances')->assertSessionHasErrors('source');

    $this->actingAs($this->admin)->get('/admin/vacances')
        ->assertSee('en échec')->assertSee('Saisir les dates d\'une année à la main', false);
});

it('US-408-P1 ouvre une année à partir de dates saisies à la main, marquées « manuelles »', function () {
    anneeEnCoursOuverte();
    $this->actingAs($this->admin)->post('/admin/vacances/saisir', datesSaisies())
        ->assertRedirect('/admin/vacances?annee=2027-2028')->assertSessionHasNoErrors();

    $annee = AnneeScolaire::where('libelle', '2027-2028')->sole();
    expect($annee->ouverte)->toBeTrue()
        ->and($annee->periodes()->where('manuelle', true)->count())->toBe(2)
        ->and(PeriodeVacances::where('nom', 'noel')->sole()->premiere_nuit->format('Y-m-d'))->toBe('2027-12-17');
    Mail::assertSent(MessageSite::class, fn ($m) => $m->gabarit === 'vacances.ouverture' && $m->hasBcc('membre@example.test'));
});

it('US-408-N2 refuse une saisie dont la fin précède le début, ou hors de l\'année scolaire', function () {
    anneeEnCoursOuverte();
    $this->actingAs($this->admin)->post('/admin/vacances/saisir', datesSaisies(['noel_dernier_jour' => '2027-12-10']))
        ->assertSessionHasErrors('saisie');
    $this->actingAs($this->admin)->post('/admin/vacances/saisir', datesSaisies(['hiver_dernier_jour' => '2028-09-10']))
        ->assertSessionHasErrors('saisie');
    $this->actingAs($this->admin)->post('/admin/vacances/saisir', datesSaisies(['annee' => '2027-2029']))
        ->assertSessionHasErrors('saisie');

    expect(AnneeScolaire::where('libelle', '2027-2028')->where('ouverte', true)->exists())->toBeFalse();
});

it('réserve la relecture et la saisie aux admins', function () {
    $this->actingAs($this->membre)->post('/admin/vacances/relire')->assertForbidden();
    $this->actingAs($this->membre)->post('/admin/vacances/saisir', datesSaisies())->assertForbidden();
});

it('dit aux membres qu\'une année s\'ouvre dès que les dates des vacances sont connues', function () {
    $this->actingAs($this->membre)->get('/calendrier/2027-11')->assertOk()
        ->assertSee('dès que les dates')->assertDontSee('quand les admins ont attribué');
});

it('US-408-P3 donne le nom de l\'année automatiquement : celle qui suit la dernière ouverte, sans champ à saisir', function () {
    // Aucune année ouverte : l'année en cours.
    $this->actingAs($this->admin)->get('/admin/vacances')->assertOk()
        ->assertSee('data-nom-auto>2026-2027</span>', false)
        ->assertSee('Enregistrer et ouvrir 2026-2027')
        ->assertDontSee('pattern="\d{4}-\d{4}"', false);

    anneeEnCoursOuverte();
    $page = $this->actingAs($this->admin)->get('/admin/vacances')->assertOk()
        ->assertSee('data-nom-auto>2027-2028</span>', false)
        ->assertSee('<input type="hidden" name="annee" value="2027-2028">', false);
    expect((string) $page->getContent())->not->toMatch('/<input(?![^>]*type="hidden")[^>]*name="annee"/');

    $this->actingAs($this->admin)->post('/admin/vacances/saisir', datesSaisies())->assertSessionHasNoErrors();
    $this->actingAs($this->admin)->get('/admin/vacances')->assertSee('data-nom-auto>2028-2029</span>', false);
});

it('US-408-N4 refuse une autre année que celle proposée, et rien au-delà de l\'année en cours + 2', function (string $annee) {
    anneeEnCoursOuverte();

    $this->actingAs($this->admin)->post('/admin/vacances/saisir', datesSaisies(['annee' => $annee]))
        ->assertSessionHasErrors(['saisie' => 'Seule l\'année 2027-2028 peut être saisie à la main.']);
    expect(AnneeScolaire::where('libelle', $annee)->exists())->toBeFalse();
})->with(['2222-2223', '2028-2029', '2026-2027']);

it('US-408-N4 ne propose plus de saisie quand l\'année en cours et les deux suivantes sont ouvertes', function () {
    foreach (['2026-2027', '2027-2028', '2028-2029'] as $l) {
        $a = AnneeScolaire::query()->create(['libelle' => $l, 'debut' => substr($l, 0, 4).'-09-01', 'fin' => substr($l, 5, 4).'-08-31']);
        $a->forceFill(['ouverte' => true])->save();
    }

    $this->actingAs($this->admin)->get('/admin/vacances')->assertOk()
        ->assertSee('aucune autre année ne peut être saisie')->assertDontSee('data-nom-auto', false);
    $this->actingAs($this->admin)->post('/admin/vacances/saisir', datesSaisies(['annee' => '2029-2030']))
        ->assertSessionHasErrors('saisie');
    expect(AnneeScolaire::where('libelle', '2029-2030')->exists())->toBeFalse();
});

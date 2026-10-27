<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Convention : chaque test commence par l'identifiant du critère d'acceptation
 * qu'il prouve (« US-301-N1 … »). scripts/verifier-criteres.php s'en sert.
 */

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Browser');

pest()->group('browser')->in('Browser');

// Réservations simultanées : de vrais processus parallèles qui doivent voir les mêmes données,
// donc pas de transaction enveloppante : les tables sont vidées entre deux tests.
pest()->extend(TestCase::class)->use(Illuminate\Foundation\Testing\DatabaseTruncation::class)->in('Concurrence');

/** Simule le service Have I Been Pwned : seuls les mots de passe listés sont « divulgués ». */
function simulerFuites(array $divulgues = []): void
{
    \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $requete) use ($divulgues) {
        $prefixe = substr($requete->url(), -5);
        $lignes = collect($divulgues)
            ->map(fn (string $mdp) => strtoupper(sha1($mdp)))
            ->filter(fn (string $h) => str_starts_with($h, strtoupper($prefixe)))
            ->map(fn (string $h) => substr($h, 5).':4242')
            ->implode("\r\n");

        return \Illuminate\Support\Facades\Http::response($lignes, 200);
    });
}

/** Active et confirme la double authentification ; renvoie le secret TOTP. */
function activerDoubleAuthentification(\App\Models\User $user, array $codesSecours = ['SECOURS-0001', 'SECOURS-0002']): string
{
    $secret = (new \PragmaRX\Google2FA\Google2FA)->generateSecretKey();
    $user->forceFill([
        'two_factor_secret' => encrypt($secret),
        'two_factor_recovery_codes' => encrypt(json_encode($codesSecours)),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $secret;
}

function codeTotp(string $secret, int $decalage = 0): string
{
    $g = new \PragmaRX\Google2FA\Google2FA;

    return $g->oathTotp($secret, $g->getTimestamp() + $decalage);
}

/** Simule le retour de Google avec l'identité donnée. */
function simulerGoogle(string $id, string $email, bool $verifiee = true): void
{
    $google = (new \Laravel\Socialite\Two\User)
        ->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verifiee])
        ->map(['id' => $id, 'email' => $email, 'name' => 'Membre Test']);

    \Laravel\Socialite\Facades\Socialite::shouldReceive('driver->user')->andReturn($google);
}

const MDP_TEST = 'Mot-de-passe-de-test-42!';
const MDP_NOUVEAU = 'Nouveau-Secret-2026!';

/** Crée les créneaux J et N de chaque jour, comme à l'ouverture d'une année. */
function creerCreneaux(string $du, string $au, ?int $periodeId = null): void
{
    $lignes = [];
    for ($d = new DateTimeImmutable($du); $d <= new DateTimeImmutable($au); $d = $d->modify('+1 day')) {
        foreach (['J', 'N'] as $t) {
            $lignes[] = ['date' => $d->format('Y-m-d'), 'type' => $t, 'periode_id' => $periodeId, 'created_at' => now(), 'updated_at' => now()];
        }
    }
    \Illuminate\Support\Facades\DB::table('creneaux')->insert($lignes);
}

/** Réserve directement par le service (préparation d'un test). */
function reserverPour(\App\Models\User $membre, array $cles, string $mode = 'ouverte', array $options = []): \App\Models\Reservation
{
    $demandes = array_map(fn (string $c) => new \App\Domain\Calendrier\Creneau(
        new DateTimeImmutable(substr($c, 0, 10)), \App\Domain\Calendrier\TypeCreneau::from(substr($c, -1))
    ), $cles);

    return app(\App\Domain\Reservations\ServiceReservation::class)->reserver(
        $membre, $demandes, $mode, $options['commentaire'] ?? null, new DateTimeImmutable(now()->format('Y-m-d H:i:s')),
        $options['nombre_personnes'] ?? 2, $options['invites'] ?? null,
    );
}

/** Met des vacances (période) sur des créneaux existants, avec ou sans lignée attribuée. */
function mettreVacances(string $du, string $au, ?string $lignee = null): void
{
    $db = \Illuminate\Support\Facades\DB::table(...);
    $annee = $db('annees_scolaires')->where('libelle', '2026-2027')->value('id')
        ?? $db('annees_scolaires')->insertGetId(['libelle' => '2026-2027', 'debut' => '2026-09-01', 'fin' => '2027-08-31']);
    $periode = $db('periodes_vacances')->insertGetId([
        'annee_scolaire_id' => $annee, 'nom' => $db('periodes_vacances')->where('annee_scolaire_id', $annee)->exists() ? 'hiver' : 'noel',
        'premiere_nuit' => $du, 'dernier_jour' => $au, 'source_empreinte' => 'test',
    ]);
    $ids = $db('creneaux')->whereBetween('date', [$du, $au])->pluck('id');
    $db('creneaux')->whereIn('id', $ids)->update(['periode_id' => $periode]);

    if ($lignee !== null) {
        $ligneeId = $db('lignees')->insertGetId(['nom' => $lignee, 'couleur' => '#2F6FB0']);
        $admin = \App\Models\User::factory()->admin()->create();
        foreach ($ids as $id) {
            $db('attributions')->insert(['creneau_id' => $id, 'lignee_id' => $ligneeId, 'saisie_par' => $admin->id]);
        }
    }
}

/** Un enregistrement du jeu officiel « fr-en-calendrier-scolaire », tel que publié (dates en UTC). */
function enregistrementScolaire(string $description, string $zone, string $debut, string $reprise, string $academie = 'Grenoble'): array
{
    return [
        'description' => $description, 'population' => '-', 'start_date' => $debut, 'end_date' => $reprise,
        'location' => $academie, 'zones' => $zone, 'annee_scolaire' => '2026-2027',
    ];
}

/**
 * Simule la source officielle pour 2026-2027 (réponse enregistrée, simplifiée) :
 * zone A avec des dates paramétrables, plus du bruit (zones B et C, Toussaint)
 * qui doit être ignoré. Les autres années ne sont pas encore publiées.
 */
function simulerCalendrier(
    ?array $noel = ['2026-12-18T23:00:00+00:00', '2027-01-03T23:00:00+00:00'],
    ?array $hiver = ['2027-02-12T23:00:00+00:00', '2027-02-28T23:00:00+00:00'],
): void {
    app()->instance(\App\Domain\Sources\UrlSourceSure::class, new \App\Domain\Sources\UrlSourceSure(fn () => ['185.75.143.24']));
    reinitialiserHttp();

    $resultats = [
        enregistrementScolaire('Vacances de la Toussaint', 'Zone A', '2026-10-16T22:00:00+00:00', '2026-11-01T23:00:00+00:00'),
        enregistrementScolaire('Vacances de Noël', 'Zone B', '2026-12-18T23:00:00+00:00', '2027-01-03T23:00:00+00:00', 'Rennes'),
        enregistrementScolaire('Vacances d\'Hiver', 'Zone B', '2027-02-19T23:00:00+00:00', '2027-03-07T23:00:00+00:00', 'Rennes'),
        enregistrementScolaire('Vacances d\'Hiver', 'Zone C', '2027-02-05T23:00:00+00:00', '2027-02-21T23:00:00+00:00', 'Paris'),
    ];
    if ($noel !== null) {
        $resultats[] = enregistrementScolaire('Vacances de Noël', 'Zone A', ...$noel);
        $resultats[] = enregistrementScolaire('Vacances de Noël', 'Zone A', ...[...$noel, 'Lyon']);
    }
    if ($hiver !== null) {
        $resultats[] = enregistrementScolaire('Vacances d\'Hiver', 'Zone A', ...$hiver);
    }

    \Illuminate\Support\Facades\Http::fake(function (\Illuminate\Http\Client\Request $requete) use ($resultats) {
        $publiee = str_contains(urldecode($requete->url()), '2026-2027');

        return \Illuminate\Support\Facades\Http::response(['total_count' => $publiee ? count($resultats) : 0, 'results' => $publiee ? $resultats : []]);
    });
}

/** Les faux de Http::fake s'empilent (le premier qui répond gagne) : on repart d'un client neuf. */
function reinitialiserHttp(): void
{
    \Illuminate\Support\Facades\Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
    \Illuminate\Support\Facades\Http::preventStrayRequests();
}

/** Ouvre 2026-2027 comme en production : source simulée, puis synchronisation. */
function ouvrirAnnee2026(): \App\Models\AnneeScolaire
{
    simulerCalendrier();
    app(\App\Domain\Vacances\PeriodesScolaires::class)->synchroniserAnnee('2026-2027');

    return \App\Models\AnneeScolaire::where('libelle', '2026-2027')->sole();
}

function periode(string $nom): \App\Models\PeriodeVacances
{
    return \App\Models\PeriodeVacances::where('nom', $nom)->sole();
}

function adminAvec2fa(array $attributs = []): \App\Models\User
{
    $admin = \App\Models\User::factory()->admin()->create($attributs);
    activerDoubleAuthentification($admin);

    return $admin->fresh();
}

/** Base GRR fictive au schéma 4.1.1 (tables temporaires, voir tests/Feature/Reprise). */
function creerBaseGrr(string $prefixe = 'grr_'): void
{
    config(['chamrousse.grr_prefixe' => $prefixe]);
    $sql = str_replace('TABLE grr_', 'TABLE '.$prefixe, (string) preg_replace('/^--.*$/m', '', (string) file_get_contents(__DIR__.'/Feature/Reprise/grr-4.1.1.sql')));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $requete) {
        \Illuminate\Support\Facades\DB::statement($requete);
    }
    // Comme l'appartement : types A (attribution), B (exclusive, avec la faute d'accent), C (ouverte).
    \Illuminate\Support\Facades\DB::table($prefixe.'type_area')->insert([
        ['type_name' => 'Attribution vacances scolaire ', 'type_letter' => 'A'],
        ['type_name' => 'Réservation éxclusive', 'type_letter' => 'B'],
        ['type_name' => 'Réservation ouverte', 'type_letter' => 'C'],
    ]);
    // Domaine 1 en heures ; domaine 2 en périodes (Journée = 0, Nuit = 1), comme l'appartement.
    \Illuminate\Support\Facades\DB::table($prefixe.'area')->insert([['id' => 1, 'area_name' => 'Heures', 'enable_periods' => 'n'], ['id' => 2, 'area_name' => 'Périodes', 'enable_periods' => 'y']]);
    \Illuminate\Support\Facades\DB::table($prefixe.'room')->insert([['id' => 1, 'area_id' => 1, 'room_name' => 'Salle'], ['id' => 2, 'area_id' => 2, 'room_name' => 'Appartement']]);
}

function grrCompte(string $login, string $email, string $motDePasse = '', string $statut = 'utilisateur', string $etat = 'actif', string $prenom = '', string $nom = ''): void
{
    \Illuminate\Support\Facades\DB::table(config('chamrousse.grr_prefixe').'utilisateurs')->insert([
        'login' => $login, 'email' => $email, 'password' => $motDePasse, 'statut' => $statut, 'etat' => $etat,
        'prenom' => $prenom ?: ucfirst($login), 'nom' => $nom ?: 'Grr',
    ]);
}

/** Entrée GRR ; les heures sont celles de Paris, comme dans l'interface de GRR. */
function grrEntree(string $beneficiaire, string $debut, string $fin, ?string $type = null, string $nom = '', string $description = '', int $repetition = 0, int $salle = 1, bool $supprimee = false): int
{
    $paris = new DateTimeZone('Europe/Paris');

    return (int) \Illuminate\Support\Facades\DB::table(config('chamrousse.grr_prefixe').'entry')->insertGetId([
        'room_id' => $salle, 'supprimer' => $supprimee ? 1 : 0,
        'start_time' => $debut === '0' ? 0 : (new DateTimeImmutable($debut, $paris))->getTimestamp(),
        'end_time' => (new DateTimeImmutable($fin, $paris))->getTimestamp(),
        'beneficiaire' => $beneficiaire, 'create_by' => $beneficiaire, 'type' => $type,
        'name' => $nom, 'description' => $description, 'repeat_id' => $repetition,
    ]);
}

/** Empreinte du contenu des tables GRR (US-802-N3). */
function sommeGrr(): string
{
    return md5(json_encode([
        \Illuminate\Support\Facades\DB::table('grr_utilisateurs')->orderBy('login')->get(),
        \Illuminate\Support\Facades\DB::table('grr_entry')->orderBy('id')->get(),
        \Illuminate\Support\Facades\DB::table('grr_type_area')->orderBy('id')->get(),
        \Illuminate\Support\Facades\DB::table('grr_area')->orderBy('id')->get(),
        \Illuminate\Support\Facades\DB::table('grr_room')->orderBy('id')->get(),
        \Illuminate\Support\Facades\DB::table('grr_j_mailuser_room')->orderBy('login')->get(),
    ]));
}

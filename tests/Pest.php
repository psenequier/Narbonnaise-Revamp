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

<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
 * Convention : chaque test commence par l'identifiant du critère d'acceptation
 * qu'il prouve (« US-301-N1 … »). scripts/verifier-criteres.php s'en sert.
 */

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature', 'Browser');

pest()->group('browser')->in('Browser');

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

<?php

declare(strict_types=1);

namespace App\Domain\Assistants;

use App\Models\AccesAssistant;
use App\Models\ClientAssistant;
use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Serveur d'autorisation OAuth 2.1 minimal pour les assistants IA (ADR 020) :
 * enregistrement dynamique des clients, code d'autorisation avec PKCE (S256 seul),
 * jeton d'accès d'une heure, jeton de renouvellement à usage unique ; l'accès expire 14 mois
 * après l'autorisation (réglage MCP_DUREE_MAX_MOIS).
 * Codes et jetons ne sont conservés que sous forme d'empreinte SHA-256.
 */
final class Autorisations
{
    public const DROITS = [AccesAssistant::LECTURE, AccesAssistant::RESERVATION];

    private const METHODES_CLIENT = ['none', 'client_secret_post', 'client_secret_basic'];

    public static function empreinte(string $secret): string
    {
        return hash('sha256', $secret);
    }

    /** 256 bits aléatoires, en base64 « URL ». */
    public static function secretAleatoire(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * RFC 7591 : un client s'enregistre de lui-même. Seules des adresses de retour HTTPS
     * (ou HTTP vers l'ordinateur local, pour les applications de bureau) sont acceptées.
     *
     * @param  array<mixed>  $metadonnees
     * @return array<string, mixed>
     */
    public function enregistrerClient(array $metadonnees): array
    {
        $adresses = $metadonnees['redirect_uris'] ?? null;
        if (! is_array($adresses) || $adresses === [] || count($adresses) > 5 || ! array_is_list($adresses)) {
            throw new ErreurOAuth('invalid_redirect_uri', 'Donnez entre une et cinq adresses de retour.');
        }
        foreach ($adresses as $adresse) {
            if (! is_string($adresse) || ! self::adresseRetourAcceptable($adresse)) {
                throw new ErreurOAuth('invalid_redirect_uri', 'Adresse de retour refusée : HTTPS obligatoire, sans fragment ni identifiants.');
            }
        }
        /** @var list<string> $adresses */
        $methode = $metadonnees['token_endpoint_auth_method'] ?? 'none';
        if (! is_string($methode) || ! in_array($methode, self::METHODES_CLIENT, true)) {
            throw new ErreurOAuth('invalid_client_metadata', 'Méthode d\'authentification du client non prise en charge.');
        }
        $types = $metadonnees['grant_types'] ?? ['authorization_code', 'refresh_token'];
        if (! is_array($types) || array_diff($types, ['authorization_code', 'refresh_token']) !== []) {
            throw new ErreurOAuth('invalid_client_metadata', 'Seuls les types « authorization_code » et « refresh_token » sont pris en charge.');
        }
        $reponses = $metadonnees['response_types'] ?? ['code'];
        if (! is_array($reponses) || array_diff($reponses, ['code']) !== []) {
            throw new ErreurOAuth('invalid_client_metadata', 'Seul le type de réponse « code » est pris en charge.');
        }

        $nom = is_string($metadonnees['client_name'] ?? null) ? self::nettoyerNom($metadonnees['client_name']) : '';
        $secret = $methode === 'none' ? null : self::secretAleatoire();

        $this->purgerClientsInutilises();
        $client = ClientAssistant::create([
            'identifiant' => self::secretAleatoire(),
            'secret_empreinte' => $secret !== null ? self::empreinte($secret) : null,
            'nom' => $nom !== '' ? $nom : 'Assistant sans nom',
            'adresses_retour' => $adresses,
            'domaine' => (string) parse_url($adresses[0], PHP_URL_HOST),
        ]);

        return array_filter([
            'client_id' => $client->identifiant,
            'client_secret' => $secret,
            'client_id_issued_at' => $client->created_at->getTimestamp(),
            'client_secret_expires_at' => $secret !== null ? 0 : null,
            'client_name' => $client->nom,
            'redirect_uris' => $adresses,
            'grant_types' => ['authorization_code', 'refresh_token'],
            'response_types' => ['code'],
            'token_endpoint_auth_method' => $methode,
        ], fn ($v) => $v !== null);
    }

    public static function adresseRetourAcceptable(string $adresse): bool
    {
        if (strlen($adresse) > 2000 || preg_match('/[\x00-\x20\x7f]/', $adresse) === 1) {
            return false;
        }
        $parties = parse_url($adresse);
        if ($parties === false || isset($parties['fragment']) || isset($parties['user']) || isset($parties['pass']) || ! isset($parties['host'], $parties['scheme'])) {
            return false;
        }
        $hote = strtolower($parties['host']);

        return match (strtolower($parties['scheme'])) {
            'https' => $hote !== '',
            'http' => in_array($hote, ['localhost', '127.0.0.1', '[::1]'], true),
            default => false,
        };
    }

    /** Nom affiché tel que déclaré, sans caractères de contrôle, 100 caractères au plus. */
    public static function nettoyerNom(string $nom): string
    {
        $nom = trim((string) preg_replace('/[\p{C}]+/u', ' ', $nom));

        return mb_substr((string) preg_replace('/\s+/u', ' ', $nom), 0, 100);
    }

    /** Un client enregistré mais jamais autorisé disparaît au bout de 7 jours. */
    private function purgerClientsInutilises(): void
    {
        ClientAssistant::query()
            ->where('created_at', '<', now()->subDays(7))
            ->whereNotIn('id', AccesAssistant::query()->select('client_id'))
            ->delete();
    }

    public function client(string $identifiant): ?ClientAssistant
    {
        return $identifiant === '' ? null : ClientAssistant::query()->where('identifiant', $identifiant)->first();
    }

    /**
     * Clic sur « Autoriser » : code à usage unique, valable 10 minutes.
     *
     * @param  list<string>  $droits
     */
    public function creerCode(User $membre, ClientAssistant $client, string $adresseRetour, string $defi, array $droits): string
    {
        $code = self::secretAleatoire();
        DB::table('assistants_codes')->insert([
            'empreinte' => self::empreinte($code),
            'client_id' => $client->id,
            'user_id' => $membre->id,
            'droits' => json_encode(array_values($droits), JSON_THROW_ON_ERROR),
            'adresse_retour' => $adresseRetour,
            'defi_pkce' => $defi,
            'expire_le' => now()->addMinutes((int) config('assistants.code_minutes', 10)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $code;
    }

    /**
     * RFC 6749 § 4.1.3 et RFC 7636 : le code s'échange une seule fois contre les jetons.
     * Un code présenté deux fois retire l'accès qu'il a créé (fuite probable).
     *
     * @return array<string, mixed>
     */
    public function echangerCode(string $code, string $adresseRetour, string $verificateur, ClientAssistant $client): array
    {
        if (preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $verificateur) !== 1) {
            throw new ErreurOAuth('invalid_grant', 'Vérificateur PKCE absent ou mal formé.');
        }

        // L'erreur est levée après la transaction : le code consommé ou l'accès retiré restent enregistrés.
        $resultat = DB::transaction(function () use ($code, $adresseRetour, $verificateur, $client): array|ErreurOAuth {
            $ligne = DB::table('assistants_codes')->where('empreinte', self::empreinte($code))->lockForUpdate()->first();
            if ($ligne === null) {
                return new ErreurOAuth('invalid_grant', 'Code inconnu.');
            }
            if ($ligne->utilise_le !== null) {
                if ($ligne->acces_id !== null) {
                    AccesAssistant::query()->whereKey((int) $ligne->acces_id)->whereNull('revoque_le')->update(['revoque_le' => now()]);
                }

                return new ErreurOAuth('invalid_grant', 'Code déjà utilisé.');
            }
            DB::table('assistants_codes')->where('id', $ligne->id)->update(['utilise_le' => now()]);

            $defiCalcule = rtrim(strtr(base64_encode(hash('sha256', $verificateur, true)), '+/', '-_'), '=');
            if (Carbon::parse((string) $ligne->expire_le)->isPast()
                || (int) $ligne->client_id !== $client->id
                || ! hash_equals((string) $ligne->adresse_retour, $adresseRetour)
                || ! hash_equals((string) $ligne->defi_pkce, $defiCalcule)) {
                return new ErreurOAuth('invalid_grant', 'Code expiré, ou client, adresse de retour ou vérificateur PKCE différents.');
            }
            $membre = User::query()->find((int) $ligne->user_id);
            if ($membre === null || ! self::compteUtilisable($membre)) {
                return new ErreurOAuth('invalid_grant', 'Compte indisponible.');
            }

            /** @var list<string> $droits */
            $droits = json_decode((string) $ligne->droits, true, flags: JSON_THROW_ON_ERROR);
            $autoriseLe = Carbon::parse((string) $ligne->created_at);
            $auPlusTard = $autoriseLe->copy()->addMonthsNoOverflow((int) config('assistants.duree_max_mois', 14));
            [$jeton, $renouvellement, $champs] = $this->nouveauxJetons($auPlusTard);

            $acces = AccesAssistant::create($champs + [
                'client_id' => $client->id,
                'user_id' => $membre->id,
                'droits' => $droits,
                'autorise_le' => $autoriseLe,
                'expire_au_plus_tard_le' => $auPlusTard,
            ]);
            DB::table('assistants_codes')->where('id', $ligne->id)->update(['acces_id' => $acces->id]);

            return $this->reponseJetons($jeton, $renouvellement, $droits);
        });

        return $resultat instanceof ErreurOAuth ? throw $resultat : $resultat;
    }

    /**
     * Renouvellement avec rotation : l'ancien jeton de renouvellement ne sert plus.
     * S'il est présenté de nouveau, l'accès est retiré (RFC 9700 § 4.14.2).
     *
     * @return array<string, mixed>
     */
    public function renouveler(string $renouvellement, ClientAssistant $client): array
    {
        $empreinte = self::empreinte($renouvellement);

        $resultat = DB::transaction(function () use ($empreinte, $client): array|ErreurOAuth {
            /** @var AccesAssistant|null $acces */
            $acces = AccesAssistant::query()->where('renouvellement_empreinte', $empreinte)->lockForUpdate()->first();
            if ($acces === null) {
                $reutilise = AccesAssistant::query()->where('ancien_renouvellement_empreinte', $empreinte)->whereNull('revoque_le')->first();
                if ($reutilise !== null) {
                    $reutilise->forceFill(['revoque_le' => now()])->save();
                    Journal::ecrire('assistant.retire', $reutilise, ['motif' => 'jeton de renouvellement réutilisé'], $reutilise->user_id);
                }

                return new ErreurOAuth('invalid_grant', 'Jeton de renouvellement inconnu ou déjà utilisé.');
            }
            $membre = User::query()->find($acces->user_id);
            if ($acces->client_id !== $client->id || $acces->revoque_le !== null || $acces->renouvellement_expire_le->isPast()
                || $membre === null || ! self::compteUtilisable($membre)) {
                return new ErreurOAuth('invalid_grant', 'Accès expiré ou retiré : reconnectez l\'assistant.');
            }

            [$jeton, $nouveau, $champs] = $this->nouveauxJetons($acces->expire_au_plus_tard_le);
            $acces->forceFill($champs + ['ancien_renouvellement_empreinte' => $empreinte])->save();

            return $this->reponseJetons($jeton, $nouveau, $acces->droits);
        });

        return $resultat instanceof ErreurOAuth ? throw $resultat : $resultat;
    }

    /**
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function nouveauxJetons(Carbon $auPlusTard): array
    {
        $jeton = self::secretAleatoire();
        $renouvellement = self::secretAleatoire();

        return [$jeton, $renouvellement, [
            'jeton_empreinte' => self::empreinte($jeton),
            'jeton_expire_le' => now()->addMinutes((int) config('assistants.jeton_minutes', 60))->min($auPlusTard),
            'renouvellement_empreinte' => self::empreinte($renouvellement),
            'renouvellement_expire_le' => $auPlusTard,
        ]];
    }

    /**
     * @param  list<string>  $droits
     * @return array<string, mixed>
     */
    private function reponseJetons(string $jeton, string $renouvellement, array $droits): array
    {
        return [
            'access_token' => $jeton,
            'token_type' => 'Bearer',
            'expires_in' => 60 * (int) config('assistants.jeton_minutes', 60),
            'refresh_token' => $renouvellement,
            'scope' => implode(' ', $droits),
        ];
    }

    /** Jeton présenté à /mcp : accès en cours, jeton non expiré, compte actif (US-1001-N1). */
    public function accesPourJeton(string $jeton): ?AccesAssistant
    {
        if ($jeton === '') {
            return null;
        }
        $acces = AccesAssistant::query()->with(['user', 'client'])->where('jeton_empreinte', self::empreinte($jeton))->first();
        if ($acces === null || $acces->revoque_le !== null || $acces->jeton_expire_le->isPast()
            || $acces->user === null || ! self::compteUtilisable($acces->user)) {
            return null;
        }

        return $acces;
    }

    public static function compteUtilisable(User $membre): bool
    {
        return $membre->actif && $membre->supprime_le === null && $membre->password !== null;
    }

    /** US-1002-N2 : désactivation, suppression ou nouveau mot de passe retirent tous les accès. */
    public function retirerTout(User $membre, string $motif): void
    {
        $nombre = AccesAssistant::query()->where('user_id', $membre->id)->whereNull('revoque_le')->update(['revoque_le' => now()]);
        if ($nombre > 0) {
            Journal::ecrire('assistant.retire', $membre, ['motif' => $motif, 'nombre' => $nombre], $membre->id);
        }
    }

    /** US-1002-P2 : « Retirer » dans le profil. */
    public function retirer(AccesAssistant $acces): void
    {
        $acces->forceFill(['revoque_le' => now()])->save();
        Journal::ecrire('assistant.retire', $acces, ['motif' => 'retiré par le membre']);
    }
}

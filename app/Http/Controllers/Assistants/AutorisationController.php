<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistants;

use App\Domain\Assistants\Autorisations;
use App\Models\AccesAssistant;
use App\Models\ClientAssistant;
use App\Models\Journal;
use App\Models\User;
use App\Notifications\AssistantConnecte;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Page « Autoriser » (US-1001-P2), après la connexion habituelle du site (mot de passe ou Google,
 * double authentification comprise). Un client ou une adresse de retour inconnus ne provoquent
 * jamais de redirection : la page d'erreur reste sur le site (US-1001-N2).
 */
final class AutorisationController
{
    public function __construct(private readonly Autorisations $autorisations) {}

    public function formulaire(Request $request): View|Response|RedirectResponse
    {
        $demande = $this->demande($request);
        if ($demande instanceof Response || $demande instanceof RedirectResponse) {
            return $demande;
        }
        [$client, $adresse] = $demande;
        // La réponse du formulaire redirige vers l'assistant : la CSP doit l'autoriser.
        $request->attributes->set('csp_form_action', self::origine($adresse));

        return view('assistants.autoriser', [
            'client' => $client,
            'champs' => [
                'client_id' => $client->identifiant, 'redirect_uri' => $adresse, 'response_type' => 'code',
                'code_challenge' => (string) $request->input('code_challenge'), 'code_challenge_method' => 'S256',
                'state' => (string) $request->input('state', ''),
            ],
        ]);
    }

    public function decider(Request $request): Response|RedirectResponse
    {
        $demande = $this->demande($request);
        if ($demande instanceof Response || $demande instanceof RedirectResponse) {
            return $demande;
        }
        [$client, $adresse] = $demande;
        $etat = (string) $request->input('state', '');

        if ($request->input('decision') !== 'autoriser') {
            Journal::ecrire('assistant.refuse', $client, ['application' => $client->nom, 'domaine' => $client->domaine]);

            return self::retour($adresse, ['error' => 'access_denied', 'state' => $etat]);
        }

        /** @var User $membre */
        $membre = $request->user();
        $droits = $request->boolean('reservation') ? Autorisations::DROITS : [AccesAssistant::LECTURE];
        $code = $this->autorisations->creerCode($membre, $client, $adresse, (string) $request->input('code_challenge'), $droits);

        Journal::ecrire('assistant.autorise', $client, ['application' => $client->nom, 'domaine' => $client->domaine, 'droits' => $droits]);
        $membre->notify(new AssistantConnecte($client->nom, $client->domaine, in_array(AccesAssistant::RESERVATION, $droits, true)));

        return self::retour($adresse, ['code' => $code, 'state' => $etat]);
    }

    /**
     * @return array{0: ClientAssistant, 1: string}|Response|RedirectResponse
     */
    private function demande(Request $request): array|Response|RedirectResponse
    {
        $client = $this->autorisations->client((string) $request->input('client_id', ''));
        $demandee = (string) $request->input('redirect_uri', '');
        $index = $client !== null ? array_search($demandee, $client->adresses_retour, true) : false;
        if ($client === null || $index === false) {
            return response()->view('assistants.erreur', [], 400);
        }
        // On repart de l'adresse enregistrée (égale à la demande), jamais du texte reçu.
        $adresse = $client->adresses_retour[$index];
        $etat = mb_substr((string) $request->input('state', ''), 0, 500);
        if ($request->input('response_type') !== 'code') {
            return self::retour($adresse, ['error' => 'unsupported_response_type', 'state' => $etat]);
        }
        $defi = (string) $request->input('code_challenge', '');
        if ($request->input('code_challenge_method') !== 'S256' || preg_match('/^[A-Za-z0-9\-_]{43,128}$/', $defi) !== 1) {
            return self::retour($adresse, ['error' => 'invalid_request', 'error_description' => 'PKCE S256 obligatoire', 'state' => $etat]);
        }

        return [$client, $adresse];
    }

    /** @param array<string, string> $parametres */
    private static function retour(string $adresse, array $parametres): RedirectResponse
    {
        $parametres = array_filter($parametres, fn ($v) => $v !== '') + ['iss' => url('/')];

        // L'adresse a été comparée exactement à celle enregistrée par le client : pas de redirection ouverte.
        return redirect()->away($adresse.(str_contains($adresse, '?') ? '&' : '?').http_build_query($parametres, '', '&', PHP_QUERY_RFC3986));
    }

    private static function origine(string $adresse): string
    {
        $p = parse_url($adresse);
        $port = isset($p['port']) ? ':'.$p['port'] : '';

        return strtolower(($p['scheme'] ?? 'https').'://'.($p['host'] ?? '')).$port;
    }
}

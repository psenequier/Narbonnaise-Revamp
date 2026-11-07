<?php

use App\Models\AccesAssistant;
use App\Models\Journal;
use App\Models\User;
use App\Notifications\AssistantConnecte;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/* US-1001 · Connecter mon assistant IA au site (épique 10, ADR 020). */

beforeEach(function () {
    $this->travelTo('2026-11-02 10:00:00');
    $this->membre = User::factory()->create(['prenom' => 'Alice']);
});

it('US-1001-P1 publie les métadonnées OAuth et envoie l\'assistant vers la connexion habituelle', function () {
    $this->get('/.well-known/oauth-protected-resource/mcp')->assertOk()
        ->assertJsonPath('resource', url('/mcp'))
        ->assertJsonPath('authorization_servers', [url('/')]);
    $this->get('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonPath('authorization_endpoint', url('/oauth/autoriser'))
        ->assertJsonPath('token_endpoint', url('/oauth/jeton'))
        ->assertJsonPath('registration_endpoint', url('/oauth/enregistrer'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256']);

    // Sans jeton, /mcp indique où trouver ces métadonnées.
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])->assertUnauthorized()
        ->assertHeader('WWW-Authenticate', 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"');

    $client = enregistrerAssistant();
    expect($client['client_id'])->toBeString()->not->toBe('')->and($client['token_endpoint_auth_method'])->toBe('none');

    // Pas encore connecté : la page de connexion du site, puis retour à la demande.
    $this->get('/oauth/autoriser?'.http_build_query(demandeAutorisation($client, pkce()['defi'])))->assertRedirect('/login');
});

it('US-1001-P2 montre le vrai nom déclaré, le domaine de retour et deux droits ; le jeton ne porte que ceux cochés', function () {
    $client = enregistrerAssistant('Claude');
    $page = $this->actingAs($this->membre)->get('/oauth/autoriser?'.http_build_query(demandeAutorisation($client, pkce()['defi'])));

    $page->assertOk()->assertSee('Autoriser Claude à accéder à votre compte ?')->assertSee('claude.ai')
        ->assertSee('Consulter le calendrier et les statistiques')->assertSee('Réserver, modifier et annuler en mon nom')
        ->assertSee('Refuser')->assertSee('Autoriser');
    expect($page->getContent())->not->toMatch('/name="reservation"[^>]*checked/');
    // La CSP laisse le formulaire revenir vers l'assistant.
    expect((string) $page->headers->get('Content-Security-Policy'))->toContain("form-action 'self' https://accounts.google.com https://claude.ai;");

    $lecture = connecterAssistant($this->membre);
    $complet = connecterAssistant($this->membre, reservation: true);

    expect($lecture['scope'])->toBe('lecture')->and($complet['scope'])->toBe('lecture reservation');
    $outils = fn (string $jeton) => collect(appelMcp($jeton, 'tools/list')->json('result.tools'))->pluck('name')->all();
    expect($outils($lecture['access_token']))->not->toContain('reserver')
        ->and($outils($complet['access_token']))->toContain('reserver', 'modifier', 'annuler');
});

it('US-1001-P3 journalise l\'autorisation et envoie une alerte de sécurité', function () {
    Notification::fake();

    connecterAssistant($this->membre, reservation: true);

    $ligne = Journal::where('action', 'assistant.autorise')->sole();
    expect($ligne->user_id)->toBe($this->membre->id)
        ->and($ligne->details)->toMatchArray(['application' => 'Claude', 'domaine' => 'claude.ai', 'droits' => ['lecture', 'reservation']]);
    Notification::assertSentTo($this->membre, AssistantConnecte::class, function (AssistantConnecte $n) {
        $mail = $n->toMail($this->membre);

        return $mail->subject === 'Un assistant IA a été connecté à votre compte' && str_contains(implode(' ', $mail->introLines), 'Claude');
    });
});

it('US-1001-P4 renouvelle le jeton d\'une heure sans reconnexion, jusqu\'à 14 mois après l\'autorisation', function () {
    $jetons = connecterAssistant($this->membre);
    expect($jetons['expires_in'])->toBe(3600);
    appelMcp($jetons['access_token'], 'ping')->assertOk();

    $this->travel(61)->minutes();
    appelMcp($jetons['access_token'], 'ping')->assertUnauthorized();

    $renouveler = fn (string $r) => $this->post('/oauth/jeton', [
        'grant_type' => 'refresh_token', 'refresh_token' => $r, 'client_id' => $jetons['client']['client_id'],
    ]);
    $nouveaux = $renouveler($jetons['refresh_token'])->assertOk()->json();
    appelMcp($nouveaux['access_token'], 'ping')->assertOk();

    // Pas d'expiration après une période sans utilisation (décision du 7 nov. 2026) : 13 mois sans servir, ça marche.
    $this->travelTo('2027-12-01 10:00:00');
    $nouveaux = $renouveler($nouveaux['refresh_token'])->assertOk()->json();

    // 14 mois après « Autoriser » (2 nov. 2026 → 2 janv. 2028), l'accès est fini.
    $this->travelTo('2028-01-02 10:01:00');
    $renouveler($nouveaux['refresh_token'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    expect(AccesAssistant::sole()->expire_au_plus_tard_le->format('Y-m-d'))->toBe('2028-01-02');
});

it('US-1001-N1 répond 401 sans aucune donnée sans jeton valide', function () {
    $jetons = connecterAssistant($this->membre);
    $refuse = function (?string $jeton) {
        $r = $jeton === null
            ? $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
            : appelMcp($jeton, 'tools/list');
        $r->assertUnauthorized()->assertJsonMissingPath('result');
    };

    $refuse(null);
    $refuse('jeton-invente');
    appelMcp($jetons['access_token'], 'tools/list')->assertOk();

    AccesAssistant::query()->update(['revoque_le' => now()]);
    $refuse($jetons['access_token']);

    AccesAssistant::query()->update(['revoque_le' => null]);
    $this->membre->forceFill(['actif' => false])->saveQuietly(); // sans l'événement : c'est le contrôle de /mcp qui refuse
    $refuse($jetons['access_token']);

    $this->membre->forceFill(['actif' => true])->saveQuietly();
    $this->travel(2)->hours();
    $refuse($jetons['access_token']);
});

it('US-1001-N2 refuse sans jeton, exige PKCE et une adresse de retour identique', function () {
    $client = enregistrerAssistant();
    $pkce = pkce();

    // Refuser : retour vers l'assistant avec « access_denied », aucun code.
    $r = $this->actingAs($this->membre)->post('/oauth/autoriser', demandeAutorisation($client, $pkce['defi'], ['decision' => 'refuser']));
    expect((string) $r->headers->get('Location'))->toStartWith(ASSISTANT_RETOUR.'?error=access_denied&state=etat-42');
    expect(DB::table('assistants_codes')->count())->toBe(0);

    // Adresse de retour différente : page d'erreur sur le site, jamais de redirection.
    $this->get('/oauth/autoriser?'.http_build_query(demandeAutorisation($client, $pkce['defi'], ['redirect_uri' => 'https://claude.ai.example.test/rappel'])))
        ->assertStatus(400)->assertSee('Demande d\'accès refusée');
    // Sans PKCE S256 : erreur renvoyée à l'assistant.
    $sansPkce = $this->get('/oauth/autoriser?'.http_build_query(demandeAutorisation($client, $pkce['defi'], ['code_challenge_method' => 'plain'])));
    expect((string) $sansPkce->headers->get('Location'))->toContain('error=invalid_request');

    $code = codeAutorise($this->membre, $client, $pkce['defi']);
    $echange = function (array $autres) use (&$code, $client, $pkce) {
        return $this->post('/oauth/jeton', $autres + [
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => ASSISTANT_RETOUR,
            'client_id' => $client['client_id'], 'code_verifier' => $pkce['verificateur'],
        ]);
    };
    $echange(['code_verifier' => pkce()['verificateur']])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    // Un code ne sert qu'une fois, même après une tentative ratée.
    $code = codeAutorise($this->membre, $client, $pkce['defi']);
    $echange(['redirect_uri' => ASSISTANT_RETOUR.'/autre'])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');
    $echange([])->assertStatus(400)->assertJsonPath('error', 'invalid_grant');

    // Code rejoué après un échange réussi : l'accès créé est retiré.
    $code = codeAutorise($this->membre, $client, $pkce['defi']);
    $jetons = $echange([])->assertOk()->json();
    $echange([])->assertStatus(400);
    appelMcp($jetons['access_token'], 'ping')->assertUnauthorized();

    // Enregistrement : HTTPS obligatoire (sauf l'ordinateur local).
    $this->postJson('/oauth/enregistrer', ['client_name' => 'X', 'redirect_uris' => ['http://pirate.example.test/cb']])
        ->assertStatus(400)->assertJsonPath('error', 'invalid_redirect_uri');
    $this->postJson('/oauth/enregistrer', ['client_name' => 'Bureau', 'redirect_uris' => ['http://localhost:33418/callback']])->assertCreated();
});

it('US-1001-N3 ne donne aucun droit d\'administration à l\'assistant d\'un admin', function () {
    $admin = User::factory()->admin()->create();
    $jetons = connecterAssistant($admin, reservation: true);

    $outils = collect(appelMcp($jetons['access_token'], 'tools/list')->json('result.tools'))->pluck('name')->sort()->values()->all();
    expect($outils)->toBe(['annuler', 'calendrier', 'disponibilites', 'mes_reservations', 'modifier', 'reserver', 'statistiques']);
    appelMcp($jetons['access_token'], 'tools/call', ['name' => 'attribuer', 'arguments' => new stdClass])->assertOk()
        ->assertJsonPath('error.code', -32602);
});

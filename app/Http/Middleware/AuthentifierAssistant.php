<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Assistants\Autorisations;
use App\Models\AccesAssistant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * /mcp n'est servi qu'avec un jeton d'accès valide (US-1001-N1) : sinon 401 sans aucune donnée,
 * avec l'adresse des métadonnées pour que l'assistant lance la connexion (RFC 9728).
 * Le membre est ensuite traité comme connecté pour cette seule requête (journal, règles).
 */
final class AuthentifierAssistant
{
    public function __construct(private readonly Autorisations $autorisations) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $jeton = (string) $request->bearerToken();
        $acces = $this->autorisations->accesPourJeton($jeton);
        if ($acces === null || $acces->user === null) {
            $entete = 'Bearer resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"'
                .($jeton !== '' ? ', error="invalid_token"' : '');

            return response()->json(['error' => 'invalid_token', 'error_description' => 'Connexion de l\'assistant requise.'], 401)
                ->header('WWW-Authenticate', $entete)->header('Cache-Control', 'no-store');
        }

        // US-1003-N3 : 60 appels par minute et par membre au plus.
        $cle = 'assistant-appels:'.$acces->user_id;
        if (RateLimiter::tooManyAttempts($cle, (int) config('assistants.appels_par_minute', 60))) {
            return response()->json(['error' => 'too_many_requests', 'error_description' => 'Trop de demandes : réessayez dans une minute.'], 429)
                ->header('Retry-After', (string) RateLimiter::availableIn($cle));
        }
        RateLimiter::hit($cle, 60);

        Auth::setUser($acces->user);
        Context::add('journal_via', 'assistant IA : '.($acces->client !== null ? $acces->client->nom.' ('.$acces->client->domaine.')' : 'inconnu'));
        $request->attributes->set(AccesAssistant::class, $acces);

        if ($acces->utilise_le === null || $acces->utilise_le->lt(now()->subMinute())) {
            $acces->forceFill(['utilise_le' => now()])->saveQuietly();
        }

        try {
            return $next($request);
        } finally {
            Context::forget('journal_via'); // rien ne déborde sur une autre requête du même processus
        }
    }
}

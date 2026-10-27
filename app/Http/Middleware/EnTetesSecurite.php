<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** En-têtes vérifiés par le test de fumée et par ZAP (A02 OWASP 2025). */
final class EnTetesSecurite
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $h = $response->headers;
        $h->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; frame-ancestors 'none'; form-action 'self' https://accounts.google.com; base-uri 'none'");
        $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('Referrer-Policy', 'same-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');
        $h->set('Cross-Origin-Resource-Policy', 'same-origin');
        $h->set('Cross-Origin-Embedder-Policy', 'require-corp');
        $h->remove('X-Powered-By');

        return $response;
    }
}

<?php

use App\Http\Middleware\EnTetesSecurite;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

$app = Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: null, // remplacée par /sante, qui vérifie aussi la tâche planifiée
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global : les en-têtes s'appliquent aussi aux pages d'erreur et aux 404 (ZAP 10038, 10063).
        $middleware->append(EnTetesSecurite::class);
        // OVH mutualisé : le site est derrière les frontaux OVH
        $middleware->trustProxies(at: '*');
        // US-602-P1 : un compte désactivé est déconnecté à la requête suivante.
        $middleware->appendToGroup('web', \App\Http\Middleware\CompteActif::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A10 (OWASP 2025) : aucune trace technique n'est montrée aux membres.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password']);
    })
    ->create();

// En production, .env et storage/ vivent dans partage/, hors des versions
// déployées, pour survivre aux bascules (RFC « Déploiement et bascule »).
$partage = dirname(__DIR__, 2).'/partage';
if (is_dir($partage)) {
    $app->useEnvironmentPath($partage);
    $app->useStoragePath($partage.'/storage');
}

return $app;

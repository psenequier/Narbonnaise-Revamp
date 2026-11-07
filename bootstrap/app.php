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
        // Épique 10 : assistant IA connecté (MCP), hors du groupe « web » pour /mcp et OAuth.
        then: function (): void {
            Illuminate\Support\Facades\Route::group([], base_path('routes/assistants.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Global : les en-têtes s'appliquent aussi aux pages d'erreur et aux 404 (ZAP 10038, 10063).
        $middleware->append(EnTetesSecurite::class);
        // OVH mutualisé : le site est derrière les frontaux OVH
        $middleware->trustProxies(at: '*');
        // US-602-P1 : un compte désactivé est déconnecté à la requête suivante.
        $middleware->appendToGroup('web', \App\Http\Middleware\CompteActif::class);
        // US-910-P2 : les visites vérifient aussi que le planificateur tourne (après la réponse).
        $middleware->append(\App\Http\Middleware\SurveillancePlanificateur::class);
        // US-1001-N1 : /mcp exige le jeton d'un assistant autorisé.
        $middleware->alias(['auth.assistant' => \App\Http\Middleware\AuthentifierAssistant::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A10 (OWASP 2025) : aucune trace technique n'est montrée aux membres.
        $exceptions->dontFlash(['password', 'password_confirmation', 'current_password']);
        // US-910-P1 : toute exception signalée (pas les 404, refus ou erreurs de saisie) prévient les admins.
        $exceptions->report(function (Throwable $e): void {
            app(\App\Domain\Surveillance\AlertesPanne::class)->exception($e);
        });
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

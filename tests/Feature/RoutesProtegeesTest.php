<?php

use Illuminate\Support\Facades\Route;

/*
 * Matrice d'autorisation (RFC, A01 OWASP 2025) : toute route de l'application
 * exige une connexion, sauf cette liste blanche. Une route ajoutée sans
 * middleware « auth » fait échouer ce test (la branche témoin le vérifie).
 */
const ROUTES_PUBLIQUES = [
    'login', 'logout', 'forgot-password', 'reset-password', 'reset-password/{token}',
    'two-factor-challenge', 'sante', '_deploiement/migrer', '_deploiement/reprise', 'up',
    'invitation/{user}/{jeton}', 'auth/google/redirection', 'auth/google/retour',
    'livewire/update', 'livewire/upload-file', 'livewire/preview-file/{filename}',
    'livewire/livewire.js', 'livewire/livewire.min.js.map', 'livewire/livewire.min.js',
    'livewire/livewire.csp.min.js.map', 'livewire/livewire.csp.min.js',
    'storage/{path}',
];

it('US-901-N2 exige une connexion sur toute route hors liste blanche', function () {
    $ouvertes = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($r) => in_array($r->uri(), ROUTES_PUBLIQUES, true) || str_starts_with($r->uri(), 'livewire'))
        ->reject(fn ($r) => collect($r->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'auth')))
        ->map(fn ($r) => implode('|', $r->methods()).' '.$r->uri())
        ->values()
        ->all();

    expect($ouvertes)->toBe([]);
});

it('US-901-P3 réserve l\'espace admin aux admins', function () {
    $sansGarde = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'admin'))
        ->reject(fn ($r) => in_array('can:admin', $r->gatherMiddleware(), true))
        ->map(fn ($r) => $r->uri())
        ->values()
        ->all();

    expect($sansGarde)->toBe([]);
});

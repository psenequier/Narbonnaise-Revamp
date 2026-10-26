<?php

use App\Http\Controllers\MigrationDeploiementController;
use App\Http\Controllers\SanteController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/sante', SanteController::class)->name('sante');

Route::post('/_deploiement/migrer', MigrationDeploiementController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:3,60');

// Tout le reste exige une connexion : le site est privé.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('/', 'calendrier')->name('calendrier');
    // Mes réservations, statistiques, météo, profil : voir le plan de dev, lot MVP 1.

    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        // Vacances et attributions, utilisateurs, paramètres, journal : lot MVP 2.
    });
});

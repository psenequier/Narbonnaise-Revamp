<?php

use App\Http\Controllers\Admin\JournalController;
use App\Http\Controllers\Admin\UtilisateursController;
use App\Http\Controllers\Admin\VacancesController;
use App\Http\Controllers\CalendrierController;
use App\Http\Controllers\ConfirmationEmailController;
use App\Http\Controllers\GoogleController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\MigrationDeploiementController;
use App\Http\Controllers\ProfilController;
use App\Http\Controllers\ReservationController;
use App\Http\Controllers\SanteController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

Route::get('/sante', SanteController::class)->name('sante');

Route::post('/_deploiement/migrer', MigrationDeploiementController::class)
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->middleware('throttle:3,60');

// Activation d'un compte invité (US-104) : le lien porte un jeton à usage unique.
Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('/invitation/{user}/{jeton}', [InvitationController::class, 'formulaire'])->name('invitation.formulaire');
    Route::post('/invitation/{user}/{jeton}', [InvitationController::class, 'activer'])->name('invitation.activer');
});

// Connexion Google (US-102) : le retour sert aussi à la liaison depuis le profil (US-107).
Route::get('/auth/google/redirection', [GoogleController::class, 'redirection'])->middleware(['guest', 'throttle:10,1'])->name('google.redirection');
Route::get('/auth/google/retour', [GoogleController::class, 'retour'])->middleware('throttle:10,1')->name('google.retour');

// Tout le reste exige une connexion : le site est privé.
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/', [CalendrierController::class, 'mois'])->name('calendrier');
    Route::get('/calendrier/{mois}', [CalendrierController::class, 'mois'])->name('calendrier.mois');
    Route::get('/saison/{saison?}', [CalendrierController::class, 'saison'])->name('saison');
    Route::get('/jour/{date}', [CalendrierController::class, 'jour'])->name('jour');

    Route::get('/reserver', [ReservationController::class, 'formulaire'])->name('reservations.formulaire');
    Route::post('/reservations', [ReservationController::class, 'enregistrer'])->middleware('throttle:30,1')->name('reservations.enregistrer');
    Route::get('/mes-reservations', [ReservationController::class, 'mes'])->name('reservations.mes');
    Route::get('/reservations/{reservation}/modifier', [ReservationController::class, 'modifier'])->name('reservations.modifier');
    Route::put('/reservations/{reservation}', [ReservationController::class, 'mettreAJour'])->middleware('throttle:30,1')->name('reservations.mettre-a-jour');
    Route::delete('/reservations/{reservation}', [ReservationController::class, 'annuler'])->name('reservations.annuler');
    Route::get('/profil', [ProfilController::class, 'afficher'])->name('profil');
    Route::put('/profil/preferences', [ProfilController::class, 'preferences'])->name('profil.preferences');
    Route::get('/profil/email/{user}/{empreinte}', ConfirmationEmailController::class)->middleware('signed')->name('profil.email.confirmer');
    Route::get('/profil/google/lier', [GoogleController::class, 'lier'])->name('profil.google.lier');
    Route::delete('/profil/google', [GoogleController::class, 'delier'])->name('profil.google.delier');
    // Statistiques, météo : voir le plan de dev, lot MVP 1.

    Route::middleware('can:admin')->prefix('admin')->name('admin.')->group(function () {
        Route::view('/', 'admin.accueil')->name('accueil');
        Route::get('/vacances', [VacancesController::class, 'index'])->name('vacances');
        Route::get('/lignees', [VacancesController::class, 'cumul'])->name('cumul');
        Route::post('/plages', [VacancesController::class, 'attribuer'])->name('plages.attribuer');
        Route::put('/plages/{plage}', [VacancesController::class, 'modifier'])->name('plages.modifier');
        Route::delete('/plages/{plage}', [VacancesController::class, 'retirer'])->name('plages.retirer');
        Route::put('/periodes/{periode}', [VacancesController::class, 'corriger'])->name('periodes.corriger');
        Route::post('/conflits/{reservation}', [VacancesController::class, 'arbitrer'])->name('conflits.arbitrer');

        Route::get('/utilisateurs', [UtilisateursController::class, 'index'])->name('utilisateurs');
        Route::post('/utilisateurs', [UtilisateursController::class, 'creer'])->name('utilisateurs.creer');
        Route::get('/utilisateurs/{user}', [UtilisateursController::class, 'fiche'])->name('utilisateurs.fiche');
        Route::post('/utilisateurs/{user}/invitation', [UtilisateursController::class, 'renvoyerInvitation'])->name('utilisateurs.invitation');
        Route::post('/utilisateurs/{user}/desactiver', [UtilisateursController::class, 'desactiver'])->name('utilisateurs.desactiver');
        Route::post('/utilisateurs/{user}/reactiver', [UtilisateursController::class, 'reactiver'])->name('utilisateurs.reactiver');
        Route::put('/utilisateurs/{user}/role', [UtilisateursController::class, 'role'])->name('utilisateurs.role');
        Route::post('/utilisateurs/{user}/reinitialisation', [UtilisateursController::class, 'reinitialisation'])->name('utilisateurs.reinitialisation');

        // Journal : lecture seule (US-604-N1).
        Route::get('/journal', JournalController::class)->name('journal');
        // Vacances et attributions, utilisateurs, paramètres, journal : lot MVP 2.
    });
});

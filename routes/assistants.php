<?php

use App\Http\Controllers\Assistants\AssistantsProfilController;
use App\Http\Controllers\Assistants\AutorisationController;
use App\Http\Controllers\Assistants\DecouverteController;
use App\Http\Controllers\Assistants\EnregistrementController;
use App\Http\Controllers\Assistants\JetonController;
use App\Http\Controllers\Assistants\McpController;
use Illuminate\Support\Facades\Route;

/*
 * Épique 10 (ADR 020) : assistant IA connecté par MCP.
 * Hors du groupe « web » (ni session ni CSRF) : découverte, enregistrement, jetons et /mcp,
 * qui exige son propre jeton (middleware « auth.assistant »).
 */
Route::get('/.well-known/oauth-protected-resource/{chemin?}', [DecouverteController::class, 'ressource'])->where('chemin', 'mcp');
Route::get('/.well-known/oauth-authorization-server', [DecouverteController::class, 'serveur']);
Route::post('/oauth/enregistrer', EnregistrementController::class)->middleware('throttle:10,60');
Route::post('/oauth/jeton', JetonController::class)->middleware('throttle:30,1');
Route::match(['get', 'post', 'delete'], '/mcp', McpController::class)->middleware('auth.assistant');

// Pages du site : connexion habituelle exigée (double authentification comprise).
Route::middleware(['web', 'auth', 'verified'])->group(function () {
    Route::get('/oauth/autoriser', [AutorisationController::class, 'formulaire'])->name('assistants.autoriser');
    Route::post('/oauth/autoriser', [AutorisationController::class, 'decider'])->middleware('throttle:20,1')->name('assistants.decider');
    Route::delete('/profil/assistants/{acces}', [AssistantsProfilController::class, 'retirer'])->whereNumber('acces')->name('assistants.retirer');
});

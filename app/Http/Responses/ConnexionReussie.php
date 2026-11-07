<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;

/** Après connexion (avec ou sans double authentification) : page demandée, sinon calendrier. */
final class ConnexionReussie implements LoginResponse, TwoFactorLoginResponse
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->intended(route('calendrier'));
    }
}

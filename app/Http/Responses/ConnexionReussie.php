<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse;

/** Après connexion : page demandée, sinon calendrier. */
final class ConnexionReussie implements LoginResponse
{
    public function toResponse($request): RedirectResponse
    {
        return redirect()->intended(route('calendrier'));
    }
}

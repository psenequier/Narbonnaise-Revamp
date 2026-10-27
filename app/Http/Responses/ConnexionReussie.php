<?php

declare(strict_types=1);

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;

/** Après connexion : calendrier, sauf admin sans double authentification (US-108-N1). */
final class ConnexionReussie implements LoginResponse, TwoFactorLoginResponse
{
    public function toResponse($request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();

        if ($user->estAdmin() && $user->two_factor_confirmed_at === null) {
            return redirect()->route('profil')
                ->with('status', 'Admin : activez la double authentification pour accéder à l\'administration.');
        }

        return redirect()->intended(route('calendrier'));
    }
}

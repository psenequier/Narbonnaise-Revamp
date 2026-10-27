<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as RedirectionExterne;
use Throwable;

/**
 * US-102 (connexion) et US-107 (liaison). Google ne crée jamais de compte :
 * il ne fait que reconnaître un membre existant.
 */
final class GoogleController
{
    private const LIAISON = 'google.liaison';

    public function redirection(Request $request): RedirectionExterne
    {
        $request->session()->forget(self::LIAISON);

        return $this->versGoogle();
    }

    public function lier(Request $request): RedirectionExterne
    {
        $request->session()->put(self::LIAISON, true);

        return $this->versGoogle();
    }

    public function retour(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        try {
            /** @var \Laravel\Socialite\Two\User $google */
            $google = Socialite::driver('google')->user(); // vérifie le paramètre state (US-102-N3)
        } catch (InvalidStateException) {
            return $this->refus('La demande de connexion a expiré. Réessayez.');
        } catch (Throwable) {
            return $this->refus('Google n\'a pas pu confirmer votre identité. Réessayez.');
        }

        $verifiee = (bool) ($google->user['email_verified'] ?? false);
        $email = Str::lower((string) $google->getEmail());
        $googleId = (string) $google->getId();

        if ($request->session()->pull(self::LIAISON) === true && Auth::check()) {
            return $this->lierAuCompte($request, $googleId);
        }

        $membre = User::where('google_id', $googleId)->first()
            ?? ($verifiee && $email !== '' ? User::where('email', $email)->first() : null);

        if (! $verifiee && $membre?->google_id !== $googleId) {
            return $this->refus('Votre adresse Google n\'est pas vérifiée.');
        }
        if ($membre === null) {
            return $this->refus('Aucun compte ne correspond à cette adresse Google. Contactez un admin.');
        }
        if (! $membre->actif) {
            return $this->refus('Ce compte est désactivé. Contactez un admin.');
        }

        Auth::login($membre);
        $request->session()->regenerate();

        return app(\Laravel\Fortify\Contracts\LoginResponse::class)->toResponse($request);
    }

    public function delier(Request $request): RedirectResponse
    {
        /** @var User $membre */
        $membre = $request->user();
        $membre->forceFill(['google_id' => null])->save();
        Journal::ecrire('google.delie', $membre);
        $membre->notify(new \App\Notifications\CompteGoogleModifie(lie: false));

        return redirect()->route('profil')->with('status', 'Votre compte Google n\'est plus lié.');
    }

    private function lierAuCompte(Request $request, string $googleId): RedirectResponse
    {
        /** @var User $membre */
        $membre = $request->user();

        if (User::where('google_id', $googleId)->whereKeyNot($membre->id)->exists()) {
            return redirect()->route('profil')->withErrors(['google' => 'Ce compte Google est déjà lié à un autre membre.']);
        }

        $membre->forceFill(['google_id' => $googleId])->save();
        Journal::ecrire('google.lie', $membre);
        $membre->notify(new \App\Notifications\CompteGoogleModifie(lie: true));

        return redirect()->route('profil')->with('status', 'Votre compte Google est lié.');
    }

    private function versGoogle(): RedirectionExterne
    {
        /** @var \Laravel\Socialite\Two\GoogleProvider $fournisseur */
        $fournisseur = Socialite::driver('google');

        // US-102-P3 : uniquement openid, email et profile.
        return $fournisseur->setScopes(['openid', 'email', 'profile'])->redirect();
    }

    private function refus(string $message): RedirectResponse
    {
        return redirect()->route(Auth::check() ? 'profil' : 'login')->withErrors(['email' => $message]);
    }
}

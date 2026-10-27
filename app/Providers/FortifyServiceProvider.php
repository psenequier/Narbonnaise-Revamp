<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use App\Http\Responses\ConnexionReussie;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\LoginResponse;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Responses\SuccessfulPasswordResetLinkRequestResponse;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(LoginResponse::class, ConnexionReussie::class);
        $this->app->singleton(TwoFactorLoginResponse::class, ConnexionReussie::class);

        // US-103-N1 : même réponse qu'un e-mail existe ou non (pas d'énumération des comptes).
        $this->app->bind(FailedPasswordResetLinkRequestResponse::class,
            fn () => new SuccessfulPasswordResetLinkRequestResponse(Password::RESET_LINK_SENT));
    }

    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::updateUserProfileInformationUsing(UpdateUserProfileInformation::class);

        Fortify::loginView(fn () => view('auth.connexion'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.mot-de-passe-oublie'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.nouveau-mot-de-passe', ['request' => $request]));
        Fortify::twoFactorChallengeView(fn () => view('auth.double-authentification'));
        Fortify::confirmPasswordView(fn () => view('auth.confirmer-mot-de-passe'));

        // US-101-N2 : compte désactivé ou invitation non activée refusés, avec le même
        // message qu'un mauvais mot de passe (US-101-N1).
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', Str::lower((string) $request->input('email')))->first();

            if ($user === null || ! $user->actif || $user->password === null) {
                Hash::check((string) $request->input('password'), Hash::make('temps-constant')); // même durée qu'un vrai essai

                return null;
            }

            return Hash::check((string) $request->input('password'), $user->password) ? $user : null;
        });

        // US-101-N3 : 5 tentatives pour un même e-mail et une même IP, puis 15 minutes de blocage.
        RateLimiter::for('login', function (Request $request) {
            $cle = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinutes(15, 5)->by($cle);
        });

        // US-108-N2 : 5 codes faux, puis 15 minutes de blocage.
        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinutes(15, 5)->by((string) $request->session()->get('login.id')));
    }
}

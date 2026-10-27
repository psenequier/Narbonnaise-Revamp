<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::loginView(fn () => view('auth.connexion'));
        Fortify::requestPasswordResetLinkView(fn () => view('auth.mot-de-passe-oublie'));
        Fortify::resetPasswordView(fn (Request $request) => view('auth.nouveau-mot-de-passe', ['request' => $request]));
        Fortify::twoFactorChallengeView(fn () => view('auth.double-authentification'));

        // 5 essais par minute pour un même compte et une même adresse IP (US-101).
        RateLimiter::for('login', function (Request $request) {
            $cle = Str::transliterate(Str::lower((string) $request->input('email')).'|'.$request->ip());

            return Limit::perMinute(5)->by($cle);
        });

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)->by((string) $request->session()->get('login.id')));
    }
}

<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // US-101-P2 : « rester connecté » vaut 30 jours, pas plus (en minutes).
        config(['auth.guards.web.remember' => 60 * 24 * 30]);
    }

    public function boot(): void
    {
        // Attrape en développement les accès paresseux et les attributs inconnus.
        Model::shouldBeStrict(! $this->app->isProduction());

        // US-108-N1 : l'espace admin exige aussi la double authentification confirmée.
        Gate::define('admin', fn (User $user) => $user->actif && $user->estAdmin() && $user->two_factor_confirmed_at !== null);

        // 12 caractères, complexité, et refus des mots de passe divulgués (HIBP, k-anonymat).
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised());
    }
}

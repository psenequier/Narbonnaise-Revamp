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
    public function boot(): void
    {
        // Attrape en développement les accès paresseux et les attributs inconnus.
        Model::shouldBeStrict(! $this->app->isProduction());

        Gate::define('admin', fn (User $user) => $user->actif && $user->estAdmin());

        // 12 caractères, complexité, et refus des mots de passe divulgués (HIBP, k-anonymat).
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised());
    }
}

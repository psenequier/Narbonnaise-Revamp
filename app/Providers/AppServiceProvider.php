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

        // Espace admin : rôle admin et compte actif. La double authentification est facultative
        // (décision de Pascal du 7 nov. 2026 ; elle reste proposée dans le profil).
        Gate::define('admin', fn (User $user) => $user->actif && $user->estAdmin());
        // US-711-N1 (ADR 019) : seuls les comptes listés dans la configuration du serveur.
        Gate::define('statistiques-lignees', fn (User $user) => $user->actif && $user->supprime_le === null
            && in_array(mb_strtolower($user->email), array_filter(array_map(
                fn (string $a) => mb_strtolower(trim($a)),
                explode(',', (string) config('chamrousse.statistiques_lignees')),
            )), true));

        // US-604-P1 : chaque connexion (mot de passe, Google, double authentification) est tracée.
        \Illuminate\Support\Facades\Event::listen(\Illuminate\Auth\Events\Login::class, function (\Illuminate\Auth\Events\Login $e) {
            if ($e->user instanceof User) {
                \App\Models\Journal::ecrire('connexion', $e->user, ['rester_connecte' => $e->remember], auteur: $e->user->id);
            }
        });

        // US-909-N3 : une sauvegarde, son nettoyage ou son contrôle en échec prévient les admins.
        $alertes = fn () => app(\App\Domain\Surveillance\AlertesPanne::class);
        \Illuminate\Support\Facades\Event::listen(\Spatie\Backup\Events\BackupHasFailed::class,
            fn (\Spatie\Backup\Events\BackupHasFailed $e) => $alertes()->sauvegarde('la sauvegarde de la nuit a échoué', $e->exception->getMessage()));
        \Illuminate\Support\Facades\Event::listen(\Spatie\Backup\Events\CleanupHasFailed::class,
            fn (\Spatie\Backup\Events\CleanupHasFailed $e) => $alertes()->sauvegarde('le nettoyage des anciennes sauvegardes a échoué', $e->exception->getMessage()));
        \Illuminate\Support\Facades\Event::listen(\Spatie\Backup\Events\UnhealthyBackupWasFound::class,
            fn (\Spatie\Backup\Events\UnhealthyBackupWasFound $e) => $alertes()->sauvegarde('la dernière sauvegarde est absente ou trop ancienne', $e->failureMessages->pluck('message')->implode(' ; ')));

        // 12 caractères, complexité, et refus des mots de passe divulgués (HIBP, k-anonymat).
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised());
    }
}

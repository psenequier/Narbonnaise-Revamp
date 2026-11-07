<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Assistants\Autorisations;
use App\Models\User;
use Illuminate\Support\ServiceProvider;

/** Épique 10 : un compte qui change de mot de passe, est désactivé ou supprimé perd ses assistants (US-1002-N2). */
final class AssistantsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        User::updated(function (User $membre): void {
            $motif = match (true) {
                $membre->wasChanged('supprime_le') && $membre->supprime_le !== null => 'compte supprimé',
                $membre->wasChanged('actif') && ! $membre->actif => 'compte désactivé',
                $membre->wasChanged('password') => 'mot de passe modifié',
                default => null,
            };
            if ($motif !== null) {
                app(Autorisations::class)->retirerTout($membre, $motif);
            }
        });
    }
}

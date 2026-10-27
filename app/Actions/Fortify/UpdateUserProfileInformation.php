<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\Journal;
use App\Models\User;
use App\Notifications\ConfirmationEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * US-105 : le membre ne modifie que son propre profil. Fortify passe toujours
 * l'utilisateur connecté, jamais un identifiant venu de la requête (US-105-N5).
 * Une nouvelle adresse n'est retenue qu'après clic sur le lien envoyé (US-105-N1).
 */
final class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /** @param array<string, string> $input */
    public function update(User $user, array $input): void
    {
        $valide = Validator::make($input, [
            'prenom' => ['required', 'string', 'max:80'],
            'nom' => ['required', 'string', 'max:80'],
            'email' => ['required', 'string', 'email:rfc', 'max:255', Rule::unique('users')->ignore($user->id)],
        ])->validateWithBag('updateProfileInformation');

        $avant = $user->only(['prenom', 'nom']);
        $user->forceFill(['prenom' => $valide['prenom'], 'nom' => $valide['nom']])->save();
        Journal::ecrire('profil.modifie', $user, ['avant' => $avant, 'apres' => $user->only(['prenom', 'nom'])]);

        $email = Str::lower((string) $valide['email']);
        if ($email !== $user->email) {
            $user->forceFill(['email_en_attente' => $email])->save();
            $lien = URL::temporarySignedRoute('profil.email.confirmer', now()->addDay(), [
                'user' => $user->id,
                'empreinte' => hash('sha256', $email),
            ]);
            Notification::route('mail', $email)->notify(new ConfirmationEmail($lien));
        }
    }
}

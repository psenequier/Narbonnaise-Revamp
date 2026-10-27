<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\Journal;
use App\Models\User;
use App\Notifications\MotDePasseModifie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;

/** US-106 : changement du mot de passe, fermeture des autres sessions, alerte. */
final class UpdateUserPassword implements UpdatesUserPasswords
{
    /** @param array<string, string> $input */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ], [
            'current_password.current_password' => 'Le mot de passe actuel est incorrect.',
        ])->validateWithBag('updatePassword');

        $user->forceFill([
            'password' => $input['password'],
            'remember_token' => Str::random(60), // invalide les « rester connecté » des autres appareils
        ])->save();

        // Sessions en base : on ferme toutes celles du membre, sauf la session en cours.
        if (config('session.driver') === 'database') {
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
        }

        $user->notify(new MotDePasseModifie);
        Journal::ecrire('mot_de_passe.modifie', $user);
    }
}

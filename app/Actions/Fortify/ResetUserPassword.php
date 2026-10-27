<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

final class ResetUserPassword implements ResetsUserPasswords
{
    /** @param array<string, string> $input */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ])->validate();

        $user->forceFill(['password' => $input['password'], 'a_reinitialiser' => false])->save();

        Journal::ecrire('mot_de_passe.reinitialise', $user);
    }
}

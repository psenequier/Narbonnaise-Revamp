<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Journal;
use App\Models\User;
use App\Notifications\EmailModifie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

/** US-105-P2 : la nouvelle adresse devient l'identifiant, l'ancienne est prévenue. */
final class ConfirmationEmailController
{
    public function __invoke(Request $request, User $user, string $empreinte): RedirectResponse
    {
        abort_unless($request->user()?->is($user) === true, 403);

        $nouvelle = $user->email_en_attente;
        abort_if($nouvelle === null || ! hash_equals(hash('sha256', $nouvelle), $empreinte), 410, 'Ce lien n\'est plus valable.');
        abort_if(User::where('email', $nouvelle)->whereKeyNot($user->id)->exists(), 409, 'Cette adresse est déjà utilisée.');

        $ancienne = $user->email;
        $user->forceFill(['email' => $nouvelle, 'email_en_attente' => null])->save();

        Notification::route('mail', $ancienne)->notify(new EmailModifie($nouvelle));
        Journal::ecrire('email.modifie', $user);

        return redirect()->route('profil')->with('status', 'Votre nouvelle adresse est confirmée.');
    }
}

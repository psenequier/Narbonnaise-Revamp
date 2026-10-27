<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Comptes\Invitations;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/** US-104 : activation du compte depuis le lien d'invitation. */
final class InvitationController
{
    public function __construct(private readonly Invitations $invitations) {}

    public function formulaire(User $user, string $jeton): View
    {
        abort_unless($this->invitations->valide($user, $jeton), 410, 'Ce lien d\'invitation n\'est plus valable. Demandez-en un nouveau à un admin.');

        return view('auth.invitation', ['user' => $user, 'jeton' => $jeton]);
    }

    public function activer(Request $request, User $user, string $jeton): RedirectResponse
    {
        abort_unless($this->invitations->valide($user, $jeton), 410, 'Ce lien d\'invitation n\'est plus valable. Demandez-en un nouveau à un admin.');

        $valide = $request->validate([
            'password' => ['required', 'string', Password::defaults(), 'confirmed'],
        ]);

        $this->invitations->activer($user, (string) $valide['password']);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('calendrier');
    }
}

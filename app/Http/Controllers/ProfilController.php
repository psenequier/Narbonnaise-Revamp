<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Journal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class ProfilController
{
    public function afficher(Request $request): View
    {
        return view('profil', ['user' => $request->user()]);
    }

    /**
     * US-109 : seules ces deux préférences sont modifiables. Les alertes de
     * sécurité et les e-mails sur ses propres créneaux ne se désactivent pas :
     * tout autre champ envoyé est ignoré (US-109-N1).
     */
    public function preferences(Request $request): RedirectResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $user->forceFill([
            'recoit_emails_autres' => $request->boolean('recoit_emails_autres'),
            'recoit_annonces_attribution' => $request->boolean('recoit_annonces_attribution'),
        ])->save();

        Journal::ecrire('preferences.modifiees', $user);

        return back()->with('status', 'Préférences enregistrées.');
    }
}

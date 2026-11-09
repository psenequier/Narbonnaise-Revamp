<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistants;

use App\Domain\Assistants\Autorisations;
use App\Models\AccesAssistant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** US-1002-P2, N1 : le membre retire un de ses propres accès ; celui d'un autre donne 404. */
final class AssistantsProfilController
{
    public function retirer(Request $request, Autorisations $autorisations, int $acces): RedirectResponse
    {
        $trouve = AccesAssistant::query()->whereKey($acces)->where('user_id', (int) $request->user()?->id)->whereNull('revoque_le')->first();
        abort_if($trouve === null, 404);
        $autorisations->retirer($trouve);

        return redirect()->route('profil')->with('status', 'Accès retiré : l\'assistant ne peut plus rien lire ni faire.');
    }
}

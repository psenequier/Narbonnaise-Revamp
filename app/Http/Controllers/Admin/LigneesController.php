<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Journal;
use App\Models\Lignee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Nom des lignées, modifiable par un admin (ni ajout ni retrait : décision du 7 nov. 2026).
 * Pas de couleur par lignée : le calendrier a une seule couleur pour les créneaux attribués.
 */
final class LigneesController
{
    public function index(): View
    {
        return view('admin.lignees', ['lignees' => Lignee::query()->orderBy('id')->get()]);
    }

    public function modifier(Request $request, Lignee $lignee): RedirectResponse
    {
        $valide = $request->validateWithBag('lignee'.$lignee->id, [
            'nom' => ['required', 'string', 'max:60', Rule::unique('lignees', 'nom')->ignore($lignee->id)],
        ], [
            'nom.unique' => 'Une autre lignée porte déjà ce nom.',
        ]);

        $avant = ['nom' => $lignee->nom];
        $lignee->update(['nom' => trim((string) $valide['nom'])]);
        Journal::ecrire('lignee.modifiee', $lignee, ['avant' => $avant, 'apres' => ['nom' => $lignee->nom]]);

        return back()->with('status', 'Lignée enregistrée.');
    }
}

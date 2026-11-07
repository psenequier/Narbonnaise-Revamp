<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Journal;
use App\Models\Lignee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** Nom et couleur des lignées, modifiables par un admin (ni ajout ni retrait : décision du 7 nov. 2026). */
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
            'couleur' => ['required', 'string', 'regex:'.Lignee::COULEUR],
        ], [
            'nom.unique' => 'Une autre lignée porte déjà ce nom.',
            'couleur.regex' => 'La couleur doit être au format #RRVVBB.',
        ]);

        $avant = ['nom' => $lignee->nom, 'couleur' => $lignee->couleur];
        $lignee->update(['nom' => trim((string) $valide['nom']), 'couleur' => strtoupper((string) $valide['couleur'])]);
        Journal::ecrire('lignee.modifiee', $lignee, ['avant' => $avant, 'apres' => ['nom' => $lignee->nom, 'couleur' => $lignee->couleur]]);

        return back()->with('status', 'Lignée enregistrée.');
    }
}

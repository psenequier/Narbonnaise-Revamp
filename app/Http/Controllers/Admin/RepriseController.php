<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/** US-802-P3, US-803 : rapports de la reprise et anciennes attributions à valider. */
final class RepriseController
{
    public function index(): View
    {
        $dernier = fn (string $type) => DB::table('reprise_rapports')->where('type', $type)->orderByDesc('id')->first();
        $decoder = fn ($r) => $r ? json_decode((string) $r->contenu, true) : null;

        return view('admin.reprise', [
            'import' => $decoder($dernier('import')),
            'controle' => $decoder($dernier('controle')),
            'propositions' => DB::table('reprise_propositions as p')->join('lignees as l', 'l.id', '=', 'p.lignee_id')
                ->where('p.statut', 'en_attente')->orderBy('p.grr_entry_id')
                ->get(['p.id', 'p.grr_entry_id', 'p.libelle', 'p.creneaux', 'l.nom as lignee']),
        ]);
    }

    /** Valider : les créneaux deviennent une attribution historique de la lignée (cumul US-405). */
    public function valider(Request $request, int $proposition): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $p = DB::table('reprise_propositions')->where('id', $proposition)->where('statut', 'en_attente')->first();
        abort_if($p === null, 404);

        DB::transaction(function () use ($p, $admin) {
            /** @var list<string> $cles */
            $cles = json_decode((string) $p->creneaux, true, flags: JSON_THROW_ON_ERROR);
            foreach ($cles as $cle) {
                DB::table('creneaux')->insertOrIgnore(['date' => substr($cle, 0, 10), 'type' => substr($cle, -1), 'created_at' => now(), 'updated_at' => now()]);
                $creneau = DB::table('creneaux')->where('date', substr($cle, 0, 10))->where('type', substr($cle, -1))->value('id');
                DB::table('attributions')->insertOrIgnore(['creneau_id' => $creneau, 'lignee_id' => $p->lignee_id, 'saisie_par' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
            }
            DB::table('reprise_propositions')->where('id', $p->id)->update(['statut' => 'validee', 'traitee_par' => $admin->id, 'updated_at' => now()]);
            Journal::ecrire('reprise.attribution_validee', null, ['grr_entry_id' => $p->grr_entry_id, 'lignee_id' => $p->lignee_id]);
        });

        return back()->with('status', 'Ancienne attribution validée.');
    }
}

<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Reprise\AttributionsReprises;
use App\Domain\Reprise\ControleReprise;
use App\Domain\Reprise\ExportBascule;
use App\Domain\Reprise\ImportGrr;
use App\Domain\Vacances\RefusVacances;
use App\Models\Journal;
use App\Models\Lignee;
use App\Models\User;
use DateTimeImmutable;
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
            'lignees' => Lignee::query()->orderBy('nom')->get(),
            'ouverte' => config('chamrousse.grr_reprise') === 'ouverte',
            'archives' => app(ExportBascule::class)->archives(),
            // Aperçu de l'e-mail d'annonce, chiffres calculés depuis la base reprise.
            'annonce' => app(\App\Domain\Reprise\AnnoncesBascule::class)
                ->contenuAnnonce(null, 'une nuit, de 22 h à 6 h', DateTimeImmutable::createFromInterface(now('Europe/Paris'))),
            'propositions' => DB::table('reprise_propositions')->where('statut', 'en_attente')->orderBy('grr_entry_id')
                ->get(['id', 'grr_entry_id', 'libelle', 'creneaux', 'lignee_id']),
        ]);
    }

    /**
     * US-805-P1 : l'admin télécharge l'archive chiffrée pour la ranger hors d'OVH.
     * Nom contrôlé (aucun chemin), téléchargement inscrit au journal.
     */
    public function archive(string $fichier): \Symfony\Component\HttpFoundation\BinaryFileResponse
    {
        abort_unless(ExportBascule::nomValide($fichier), 404);
        $chemin = ExportBascule::dossier().'/'.$fichier;
        abort_unless(is_file($chemin), 404);
        Journal::ecrire('reprise.archive_telechargee', null, ['fichier' => $fichier]);

        return response()->download($chemin, $fichier, ['Content-Type' => 'application/zip']);
    }

    /** US-806-P1 : annonce de la bascule (pas de SSH sur l'offre Starter : bouton d'admin). */
    public function annoncer(Request $request, \App\Domain\Reprise\AnnoncesBascule $annonces): RedirectResponse
    {
        $valide = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'duree' => ['required', 'string', 'max:100'],
        ]);
        $paris = new \DateTimeZone('Europe/Paris');
        try {
            $n = $annonces->annoncer(new DateTimeImmutable((string) $valide['date'], $paris), (string) $valide['duree'], DateTimeImmutable::createFromInterface(now('Europe/Paris')));
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['date' => $e->getMessage()]);
        }

        return back()->with('status', "Annonce envoyée à {$n} membre(s).");
    }

    /** US-806-P2 : messages du jour J, une seule fois. */
    public function mettreEnService(\App\Domain\Reprise\AnnoncesBascule $annonces): RedirectResponse
    {
        abort_if(DB::table('journal')->where('action', 'bascule.mise_en_service')->exists(), 409, 'Les messages de mise en service sont déjà partis.');
        $r = $annonces->mettreEnService();

        return back()->with('status', "Mise en service annoncée : {$r['liens']} lien(s) de mot de passe, {$r['adresse']} message(s) d'adresse.");
    }

    /**
     * Pas de SSH sur l'offre Starter : l'import se lance d'ici, la nuit de la bascule
     * (et des répétitions). Relançable sans doublon ; fermé après la bascule.
     */
    public function lancer(ImportGrr $import, ControleReprise $controle): RedirectResponse
    {
        abort_unless(config('chamrousse.grr_reprise') === 'ouverte', 403, 'La reprise est fermée depuis la bascule.');
        @set_time_limit(300);

        try {
            $import->importer();
        } catch (\Illuminate\Database\QueryException $e) {
            report($e);

            return back()->withErrors(['reprise' => 'Les tables GRR sont introuvables : vérifiez GRR_PREFIXE dans partage/.env.']);
        }
        app(AttributionsReprises::class)->appliquerSaison();
        $rapport = $controle->rapport();

        return back()->with('status', $rapport['valide'] ? 'Reprise terminée : contrôle validé.' : 'Reprise terminée, mais le contrôle signale des écarts : voir ci-dessous.');
    }

    /**
     * Valider : l'admin confirme (ou choisit) la lignée. Dans une période de vacances
     * connue, la proposition devient une plage (écran Vacances) ; avant, une
     * attribution historique (cumul US-405).
     */
    public function valider(Request $request, AttributionsReprises $reprises, int $proposition): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();
        $valide = $request->validate(['lignee_id' => ['required', 'integer', 'exists:lignees,id']], [
            'lignee_id.required' => 'Choisissez la lignée.',
        ]);
        $p = DB::table('reprise_propositions')->where('id', $proposition)->where('statut', 'en_attente')->first();
        abort_if($p === null, 404);

        try {
            $plage = $reprises->valider($p, (int) $valide['lignee_id'], $admin);
        } catch (RefusVacances $e) {
            return back()->withErrors(['proposition' => "Entrée GRR n° {$p->grr_entry_id} : ".$e->getMessage()]);
        }

        return back()->with('status', $plage ? 'Attribution validée : elle figure sur l\'écran Vacances.' : 'Ancienne attribution validée.');
    }
}

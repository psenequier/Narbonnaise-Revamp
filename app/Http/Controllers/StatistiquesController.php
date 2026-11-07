<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Equite\OccupationParLignee;
use App\Domain\Statistiques\Statistiques;
use Illuminate\Support\Facades\Gate;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Statistiques d'occupation (F11) : appartement seulement. Seuls « vue », « saison » et « annee »
 * sont lus ; tout autre paramètre (user, membre, lignee…) est ignoré (US-701-N3). L'encadré par lignée
 * (US-711, ADR 019) ne dépend d'aucun paramètre, seulement du compte connecté.
 */
final class StatistiquesController
{
    public function __invoke(Request $request, Statistiques $stats, OccupationParLignee $parLignee): View
    {
        $aujourdhui = CalendrierController::aujourdhui();
        $saisons = $stats->saisons($aujourdhui);
        $vue = match ($request->query('vue')) {
            'annee' => 'annee', 'tout' => 'tout', default => 'saison',
        };

        $saisonCourante = Statistiques::anneeDeSaison($aujourdhui).'-'.(Statistiques::anneeDeSaison($aujourdhui) + 1);
        $choixSaisons = array_reverse(array_column($saisons, 'saison'));
        if ($choixSaisons === []) {
            $choixSaisons = [$saisonCourante];
        }
        $anneeCourante = (int) $aujourdhui->format('Y');
        $premiereAnnee = $saisons === [] ? $anneeCourante : (int) substr($saisons[0]['saison'], 0, 4);
        $choixAnnees = range($anneeCourante, min($premiereAnnee, $anneeCourante));

        if ($vue === 'tout') {
            // US-703-P2 : de la première occupation connue jusqu'à aujourd'hui.
            // Même fuseau que les autres bornes (dates civiles sans heure).
            $fin = new \DateTimeImmutable($aujourdhui->format('Y-m-d'));
            $debut = $stats->premiereOccupation() ?? $fin;
            $bornes = ['debut' => min($debut, $fin), 'fin' => $fin, 'approchee' => false];
            $choisi = '';
        } elseif ($vue === 'annee') {
            $annee = $request->integer('annee', $anneeCourante);
            $annee = in_array($annee, $choixAnnees, true) ? $annee : $anneeCourante;
            $bornes = Statistiques::bornesAnnee($annee);
            $choisi = (string) $annee;
        } else {
            $saison = $request->string('saison', $saisonCourante)->toString();
            $saison = in_array($saison, $choixSaisons, true) ? $saison : $choixSaisons[0];
            $bornes = $stats->bornesSaison($saison);
            $choisi = $saison;
        }

        $p = $stats->periode($bornes['debut'], $bornes['fin']);

        return view('statistiques', [
            'vue' => $vue,
            'choisi' => $choisi,
            'choixSaisons' => $choixSaisons,
            'choixAnnees' => $choixAnnees,
            'bornes' => $bornes,
            'p' => $p,
            'barres' => $vue === 'tout' ? Statistiques::parAnnee($p['mois']) : $p['mois'],
            'saisons' => $saisons,
            // US-711-N1 (ADR 019) : calculé et envoyé à la vue pour les seuls comptes désignés.
            'parLignee' => Gate::allows('statistiques-lignees') ? $parLignee->periode($bornes['debut'], $bornes['fin']) : null,
        ]);
    }
}

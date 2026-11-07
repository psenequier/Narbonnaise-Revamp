<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Statistiques\Statistiques;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Statistiques d'occupation (F11) : appartement seulement. Seuls « vue », « saison » et « annee »
 * sont lus ; tout autre paramètre (user, membre, lignee…) est ignoré (US-701-N3).
 */
final class StatistiquesController
{
    public function __invoke(Request $request, Statistiques $stats): View
    {
        $aujourdhui = CalendrierController::aujourdhui();
        $saisons = $stats->saisons($aujourdhui);
        $vue = $request->query('vue') === 'annee' ? 'annee' : 'saison';

        $saisonCourante = Statistiques::anneeDeSaison($aujourdhui).'-'.(Statistiques::anneeDeSaison($aujourdhui) + 1);
        $choixSaisons = array_reverse(array_column($saisons, 'saison'));
        if ($choixSaisons === []) {
            $choixSaisons = [$saisonCourante];
        }
        $anneeCourante = (int) $aujourdhui->format('Y');
        $premiereAnnee = $saisons === [] ? $anneeCourante : (int) substr($saisons[0]['saison'], 0, 4);
        $choixAnnees = range($anneeCourante, min($premiereAnnee, $anneeCourante));

        if ($vue === 'annee') {
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

        return view('statistiques', [
            'vue' => $vue,
            'choisi' => $choisi,
            'choixSaisons' => $choixSaisons,
            'choixAnnees' => $choixAnnees,
            'bornes' => $bornes,
            'p' => $stats->periode($bornes['debut'], $bornes['fin']),
            'saisons' => $saisons,
        ]);
    }
}

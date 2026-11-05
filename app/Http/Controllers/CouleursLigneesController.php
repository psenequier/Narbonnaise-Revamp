<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Calendrier\AffichageCreneau;
use App\Models\Lignee;
use Illuminate\Http\Response;

/**
 * Feuille de style des couleurs de lignée (vues mois et saison). La politique de
 * sécurité interdit les styles en ligne : chaque couleur devient une classe « lc-xxxxxx » (AffichageCreneau::classeCouleur).
 */
final class CouleursLigneesController
{
    public function __invoke(): Response
    {
        $css = '';
        foreach (Lignee::query()->pluck('couleur')->unique() as $couleur) {
            $classe = AffichageCreneau::classeCouleur((string) $couleur);
            if ($classe !== null) {
                $css .= ".{$classe}{background-color:{$couleur};border-color:{$couleur};color:#fff}\n";
            }
        }

        return response($css, 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'private, max-age=300']);
    }
}

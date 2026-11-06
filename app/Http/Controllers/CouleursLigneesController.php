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
                $texte = self::claire((string) $couleur) ? '#1F2140' : '#fff'; // pastels de la charte : texte nuit
                $css .= ".{$classe}{background-color:{$couleur};border-color:{$couleur};color:{$texte}}\n";
            }
        }

        return response($css, 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'private, max-age=300']);
    }

    /** Couleur claire (luminance relative > 0,4) : on écrit en nuit dessus plutôt qu'en blanc. */
    private static function claire(string $couleur): bool
    {
        [$r, $v, $b] = array_map(fn (string $h) => hexdec($h) / 255, str_split(ltrim($couleur, '#'), 2));
        $lin = fn (float $c) => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $lin($r) + 0.7152 * $lin($v) + 0.0722 * $lin($b) > 0.4;
    }
}

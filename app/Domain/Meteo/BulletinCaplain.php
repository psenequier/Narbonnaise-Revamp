<?php

declare(strict_types=1);

namespace App\Domain\Meteo;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bulletin écrit de M. Caplain pour les Alpes du Nord (US-707, décision du 7 nov. 2026).
 * Sa page est relue côté serveur au plus toutes les 30 minutes ; seul le texte du tableau
 * « forecast » est gardé, jamais son HTML. En cas d'échec, le dernier bulletin connu reste.
 * L'adresse est fixe (pas un paramètre modifiable) : aucun risque de requête détournée.
 */
final class BulletinCaplain
{
    public const URL = 'http://mto38.free.fr/';

    public const RELECTURE_SECONDES = 1800;

    private const CLE = 'meteo.caplain';

    private const CLE_FRAICHEUR = 'meteo.caplain.frais';

    private const MAX_SECTIONS = 12;

    private const MAX_CARACTERES = 2000;

    /**
     * Dernier bulletin connu, ou null si aucun (US-707-N1).
     *
     * @return array{region: string, emis: string, sections: list<array{titre: string, texte: string, donnees: list<string>}>}|null
     */
    public function lire(): ?array
    {
        /** @var array{region: string, emis: string, sections: list<array{titre: string, texte: string, donnees: list<string>}>}|null $connu */
        $connu = Cache::get(self::CLE);
        // US-707-P2 : une seule lecture par demi-heure ; un échec attend 10 minutes.
        if (! Cache::add(self::CLE_FRAICHEUR, 1, 600)) {
            return $connu;
        }
        $nouveau = $this->relire();
        if ($nouveau === null) {
            return $connu;
        }
        Cache::forever(self::CLE, $nouveau);
        Cache::put(self::CLE_FRAICHEUR, 1, self::RELECTURE_SECONDES);

        return $nouveau;
    }

    /** @return array{region: string, emis: string, sections: list<array{titre: string, texte: string, donnees: list<string>}>}|null */
    private function relire(): ?array
    {
        try {
            $reponse = Http::timeout(10)->connectTimeout(10)
                ->withHeaders(['User-Agent' => 'Chamrousse-reservation/1.0 (site familial, une lecture par demi-heure)'])
                ->get(self::URL);
            if (! $reponse->successful()) {
                return null;
            }

            return self::analyser($reponse->body());
        } catch (Throwable) {
            return null; // site muet, délai dépassé : on garde le dernier bulletin
        }
    }

    /**
     * Extrait le texte du bulletin d'une page de mto38 (Windows-1252 ou UTF-8).
     *
     * @return array{region: string, emis: string, sections: list<array{titre: string, texte: string, donnees: list<string>}>}|null
     */
    public static function analyser(string $page): ?array
    {
        // US-707-N3 : la page est en Windows-1252 ; tout passe en UTF-8, puis en entités
        // numériques pour que l'analyseur ne dépende d'aucune déclaration d'encodage.
        $utf8 = mb_check_encoding($page, 'UTF-8') ? $page : mb_convert_encoding($page, 'UTF-8', 'Windows-1252');
        $ascii = mb_encode_numericentity($utf8, [0x80, 0x10FFFF, 0, 0x1FFFFF], 'UTF-8');

        $doc = new DOMDocument;
        $ancien = libxml_use_internal_errors(true);
        $lu = $doc->loadHTML($ascii, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($ancien);
        if (! $lu) {
            return null;
        }
        $xpath = new DOMXPath($doc);
        $tables = $xpath->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' forecast ')]");
        $table = $tables === false ? null : $tables->item(0);
        if (! $table instanceof DOMElement) {
            return null; // structure changée : rien n'est deviné
        }

        $premier = function (string $chemin) use ($xpath, $table): string {
            $noeuds = $xpath->query($chemin, $table);

            $noeud = $noeuds === false ? null : $noeuds->item(0);

            return $noeud instanceof DOMElement ? self::texte($noeud->textContent) : '';
        };
        $region = $premier('.//th');
        $emis = $premier('.//td/b/i');

        /** @var list<array{titre: string, texte: string, donnees: list<string>}> $sections */
        $sections = [];
        /** @var array{titre: string, texte: string, donnees: list<string>}|null $courante */
        $courante = null;
        $divs = $xpath->query(".//td/div[@class='titre' or @class='blabla' or @class='donnees']", $table);
        foreach ($divs === false ? [] : $divs as $div) {
            if (! $div instanceof DOMElement) {
                continue;
            }
            $classe = $div->getAttribute('class');
            if ($classe === 'titre') {
                if ($courante !== null) {
                    $sections[] = $courante;
                }
                if (count($sections) >= self::MAX_SECTIONS) {
                    $courante = null;
                    break;
                }
                $courante = ['titre' => self::titre($div->textContent), 'texte' => '', 'donnees' => []];

                continue;
            }
            if ($courante === null) {
                continue;
            }
            if ($classe === 'blabla') {
                $courante['texte'] = self::texte($div->textContent);
            } else {
                // Les infobulles (« bassin grenoblois », « 1 noeud = … ») ne sont pas reprises.
                foreach (iterator_to_array($div->getElementsByTagName('span')) as $infobulle) {
                    $infobulle->parentNode?->removeChild($infobulle);
                }
                $donnees = [];
                foreach (preg_split('/\R/u', $div->textContent) ?: [] as $ligne) {
                    $ligne = self::texte($ligne);
                    if ($ligne !== '') {
                        $donnees[] = $ligne;
                    }
                }
                $courante['donnees'] = $donnees;
            }
        }
        if ($courante !== null) {
            $sections[] = $courante;
        }
        $sections = array_values(array_filter($sections, fn (array $s) => $s['titre'] !== '' && ($s['texte'] !== '' || $s['donnees'] !== [])));
        if ($sections === []) {
            return null;
        }

        return ['region' => $region, 'emis' => $emis, 'sections' => $sections];
    }

    /** Texte sur une ligne, flèches lisibles, longueur bornée. */
    private static function texte(string $brut): string
    {
        $t = trim((string) preg_replace('/\s+/u', ' ', $brut));
        $t = str_replace('->', ' → ', $t);
        $t = trim((string) preg_replace('/ {2,}/u', ' ', $t));

        return mb_substr($t, 0, self::MAX_CARACTERES);
    }

    /** « demain Samedi 7 Novembre: » devient « Demain samedi 7 novembre ». */
    private static function titre(string $brut): string
    {
        $t = rtrim(self::texte($brut), ' :');
        $t = mb_strtolower($t);

        return mb_strtoupper(mb_substr($t, 0, 1)).mb_substr($t, 1);
    }
}

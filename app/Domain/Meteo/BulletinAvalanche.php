<?php

declare(strict_types=1);

namespace App\Domain\Meteo;

use App\Domain\Parametres;
use App\Domain\Sources\UrlSourceSure;
use DateTimeImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SimpleXMLElement;
use Throwable;

/**
 * Risque d'avalanche et enneigement du massif (US-708, US-709, ADR 011) : bulletin BRA de
 * Météo-France, lu côté serveur au plus une fois par heure, avec la clé du portail des API
 * gardée dans la configuration. Rien n'est deviné : une réponse illisible n'est pas affichée.
 *
 * @phpstan-type Bulletin array{
 *   massif: string, emis: string, echeance: string, resume: string, demain: int|null,
 *   niveaux: list<array{niveau: int, ou: string|null}>,
 *   enneigement: array{altitudes: list<array{altitude: int, nord: int|null, sud: int|null}>, limite_nord: int|null, limite_sud: int|null}|null
 * }
 * @psalm-type Bulletin = array{
 *   massif: string, emis: string, echeance: string, resume: string, demain: int|null,
 *   niveaux: list<array{niveau: int, ou: string|null}>,
 *   enneigement: array{altitudes: list<array{altitude: int, nord: int|null, sud: int|null}>, limite_nord: int|null, limite_sud: int|null}|null
 * }
 */
final class BulletinAvalanche
{
    public const NOMS = [1 => 'Faible', 2 => 'Limité', 3 => 'Marqué', 4 => 'Fort', 5 => 'Très fort'];

    /** Un bulletin échu depuis plus longtemps n'est plus affiché (US-708-N1). */
    public const PEREMPTION_HEURES = 24;

    private const CLE = 'meteo.avalanche';

    private const CLE_FRAICHEUR = 'meteo.avalanche.frais';

    public function __construct(private readonly Parametres $parametres, private readonly UrlSourceSure $filtre) {}

    /** @return Bulletin|null */
    public function lire(): ?array
    {
        $cle = config('services.meteofrance.cle');
        if (! is_string($cle) || $cle === '') {
            return null; // US-708-N1 : pas de clé, pas d'appel
        }
        /** @var Bulletin|null $connu */
        $connu = Cache::get(self::CLE);
        if (Cache::add(self::CLE_FRAICHEUR, 1, 600)) {
            $nouveau = $this->appeler($cle);
            if ($nouveau !== null) {
                Cache::forever(self::CLE, $nouveau);
                Cache::put(self::CLE_FRAICHEUR, 1, 3600);
                $connu = $nouveau;
            }
        }

        return $connu !== null && self::enVigueur($connu) ? $connu : null;
    }

    /** @param Bulletin $b */
    public static function enVigueur(array $b): bool
    {
        $echeance = new DateTimeImmutable($b['echeance']);

        return $echeance->modify('+'.self::PEREMPTION_HEURES.' hours') >= now()->toDateTimeImmutable();
    }

    /** @return Bulletin|null */
    private function appeler(string $cle): ?array
    {
        try {
            $url = $this->filtre->valider($this->parametres->lire('source.meteofrance_bra'));
            $reponse = Http::timeout(10)->connectTimeout(10)
                ->withHeaders(['apikey' => $cle, 'Accept' => 'application/xml'])
                ->get($url, ['id-massif' => $this->parametres->lire('station.massif_bra'), 'format' => 'xml']);
            if (! $reponse->successful()) {
                return null;
            }

            return self::analyser($reponse->body());
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Lit un bulletin BRA (XML de Météo-France). Les noms d'éléments et d'attributs sont
     * comparés sans tenir compte de la casse.
     *
     * @return Bulletin|null
     */
    public static function analyser(string $xml): ?array
    {
        if ($xml === '' || strlen($xml) > 1_000_000) {
            return null;
        }
        $ancien = libxml_use_internal_errors(true);
        try {
            $racine = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($ancien);
        }
        if (! $racine instanceof SimpleXMLElement) {
            return null;
        }
        $bulletin = strtoupper($racine->getName()) === 'BULLETINS_NEIGE_AVALANCHE' ? $racine : self::element($racine, 'BULLETINS_NEIGE_AVALANCHE');
        $risque = $bulletin === null ? null : self::element($bulletin, 'RISQUE');
        if ($bulletin === null || $risque === null) {
            return null;
        }
        $emis = self::date(self::attribut($bulletin, 'DATEBULLETIN'));
        $echeance = self::date(self::attribut($bulletin, 'DATEECHEANCE'));
        $r1 = self::niveau(self::attribut($risque, 'RISQUE1'));
        if ($emis === null || $echeance === null || $r1 === null) {
            return null; // US-708-N3
        }
        $r2 = self::niveau(self::attribut($risque, 'RISQUE2'));
        $altitude = self::entier(self::attribut($risque, 'ALTITUDE'));
        $niveaux = $r2 !== null && $altitude !== null
            ? [['niveau' => $r2, 'ou' => "au-dessus de {$altitude} m"], ['niveau' => $r1, 'ou' => "en dessous de {$altitude} m"]]
            : [['niveau' => $r1, 'ou' => null]];

        $resume = self::element($bulletin, 'RESUME');

        return [
            'massif' => mb_convert_case(mb_strtolower(self::attribut($bulletin, 'MASSIF') ?? ''), MB_CASE_TITLE),
            'emis' => $emis,
            'echeance' => $echeance,
            'resume' => $resume === null ? '' : mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $resume)), 0, 600),
            'demain' => self::niveau(self::attribut($risque, 'RISQUEMAXIJ2')),
            'niveaux' => $niveaux,
            'enneigement' => self::enneigement($bulletin),
        ];
    }

    /** @return array{altitudes: list<array{altitude: int, nord: int|null, sud: int|null}>, limite_nord: int|null, limite_sud: int|null}|null */
    private static function enneigement(SimpleXMLElement $bulletin): ?array
    {
        $e = self::element($bulletin, 'ENNEIGEMENT');
        if ($e === null) {
            return null;
        }
        $altitudes = [];
        foreach ($e->children() as $niveau) {
            if (strtoupper($niveau->getName()) !== 'NIVEAU') {
                continue;
            }
            $alt = self::entier(self::attribut($niveau, 'ALTI'));
            if ($alt === null) {
                continue;
            }
            $altitudes[$alt] = ['altitude' => $alt, 'nord' => self::entier(self::attribut($niveau, 'N')), 'sud' => self::entier(self::attribut($niveau, 'S'))];
        }
        krsort($altitudes); // US-709-P1 : de la plus haute à la plus basse
        $limiteNord = self::entier(self::attribut($e, 'LIMITENORD'));
        $limiteSud = self::entier(self::attribut($e, 'LIMITESUD'));
        if ($altitudes === [] && $limiteNord === null && $limiteSud === null) {
            return null;
        }

        return ['altitudes' => array_values($altitudes), 'limite_nord' => $limiteNord, 'limite_sud' => $limiteSud];
    }

    private static function element(SimpleXMLElement $parent, string $nom): ?SimpleXMLElement
    {
        foreach ($parent->children() as $enfant) {
            if (strtoupper($enfant->getName()) === $nom) {
                return $enfant;
            }
            $trouve = self::element($enfant, $nom);
            if ($trouve !== null) {
                return $trouve;
            }
        }

        return null;
    }

    private static function attribut(SimpleXMLElement $el, string $nom): ?string
    {
        foreach ($el->attributes() ?? [] as $cle => $valeur) {
            if (strtoupper((string) $cle) === $nom) {
                return trim((string) $valeur);
            }
        }

        return null;
    }

    private static function niveau(?string $v): ?int
    {
        $n = self::entier($v);

        return $n !== null && $n >= 1 && $n <= 5 ? $n : null;
    }

    private static function entier(?string $v): ?int
    {
        return $v !== null && preg_match('/^-?\d+$/', $v) === 1 && (int) $v >= 0 ? (int) $v : null;
    }

    private static function date(?string $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        try {
            return (new DateTimeImmutable($v, new \DateTimeZone('Europe/Paris')))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }
}

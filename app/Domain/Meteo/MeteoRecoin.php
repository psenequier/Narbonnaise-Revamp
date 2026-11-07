<?php

declare(strict_types=1);

namespace App\Domain\Meteo;

use App\Domain\Parametres;
use App\Domain\Sources\UrlSourceSure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Météo du Recoin (F12, US-704, US-705, ADR 011). Appel côté serveur seulement, au plus
 * une fois par heure ; en cas d'échec, la dernière valeur connue reste affichée avec son heure.
 */
final class MeteoRecoin
{
    public const DELAI_SECONDES = 10;

    private const CLE = 'meteo.recoin';

    private const CLE_FRAICHEUR = 'meteo.recoin.frais';

    /** Codes météo de l'OMS (Open-Meteo) : texte du ciel et pictogramme. */
    private const CIELS = [
        0 => ['Soleil', 'soleil'], 1 => ['Plutôt ensoleillé', 'eclaircies'], 2 => ['Éclaircies', 'eclaircies'], 3 => ['Nuageux', 'nuage'],
        45 => ['Brouillard', 'brouillard'], 48 => ['Brouillard givrant', 'brouillard'],
        51 => ['Bruine', 'pluie'], 53 => ['Bruine', 'pluie'], 55 => ['Bruine forte', 'pluie'], 56 => ['Bruine verglaçante', 'pluie'], 57 => ['Bruine verglaçante', 'pluie'],
        61 => ['Pluie faible', 'pluie'], 63 => ['Pluie', 'pluie'], 65 => ['Pluie forte', 'pluie'], 66 => ['Pluie verglaçante', 'pluie'], 67 => ['Pluie verglaçante', 'pluie'],
        71 => ['Neige faible', 'neige'], 73 => ['Neige', 'neige'], 75 => ['Neige forte', 'neige'], 77 => ['Grains de neige', 'neige'],
        80 => ['Averses', 'pluie'], 81 => ['Averses', 'pluie'], 82 => ['Fortes averses', 'pluie'], 85 => ['Averses de neige', 'neige'], 86 => ['Fortes averses de neige', 'neige'],
        95 => ['Orage', 'orage'], 96 => ['Orage et grêle', 'orage'], 99 => ['Orage et grêle', 'orage'],
    ];

    public function __construct(private readonly Parametres $parametres, private readonly UrlSourceSure $filtre) {}

    /** @return array{texte: string, icone: string} un code inconnu donne « Ciel inconnu » (US-704-N4) */
    public static function ciel(mixed $code): array
    {
        $c = is_numeric($code) ? (int) $code : -1;
        [$texte, $icone] = self::CIELS[$c] ?? ['Ciel inconnu', 'inconnu'];

        return ['texte' => $texte, 'icone' => $icone];
    }

    /**
     * Dernière météo connue, ou null si aucune (US-704-N2).
     *
     * @return array{le: int, actuel: array<string, float|int|null>, jours: list<array{date: string, code: int|null, max: float|null, min: float|null, neige: float|null}>}|null
     */
    public function lire(): ?array
    {
        /** @var array{le: int, actuel: array<string, float|int|null>, jours: list<array{date: string, code: int|null, max: float|null, min: float|null, neige: float|null}>}|null $connue */
        $connue = Cache::get(self::CLE);
        // US-704-P3 : un seul appel par heure, quel que soit le nombre de visites ; un échec attend 10 minutes.
        if (! Cache::add(self::CLE_FRAICHEUR, 1, 600)) {
            return $connue;
        }
        $nouvelle = $this->appeler();
        if ($nouvelle === null) {
            return $connue; // US-704-N1 : la dernière valeur connue, avec son heure
        }
        Cache::forever(self::CLE, $nouvelle);
        Cache::put(self::CLE_FRAICHEUR, 1, 3600);

        return $nouvelle;
    }

    /** @return array{le: int, actuel: array<string, float|int|null>, jours: list<array{date: string, code: int|null, max: float|null, min: float|null, neige: float|null}>}|null */
    private function appeler(): ?array
    {
        try {
            $url = $this->filtre->valider($this->parametres->lire('source.open_meteo'));
            $reponse = Http::timeout(self::DELAI_SECONDES)->connectTimeout(self::DELAI_SECONDES)->acceptJson()->get($url, [
                'latitude' => $this->parametres->lire('station.latitude'),
                'longitude' => $this->parametres->lire('station.longitude'),
                'elevation' => $this->parametres->lire('station.altitude'),
                'current' => 'temperature_2m,apparent_temperature,relative_humidity_2m,weather_code,wind_speed_10m,freezing_level_height,snow_depth',
                'daily' => 'weather_code,temperature_2m_max,temperature_2m_min,snowfall_sum',
                'timezone' => 'Europe/Paris',
                'forecast_days' => 7,
            ]);
        } catch (InvalidArgumentException|ConnectionException) {
            return null;
        }
        if (! $reponse->successful()) {
            return null;
        }
        $json = json_decode($reponse->body(), true);
        if (! is_array($json) || ! is_array($json['current'] ?? null) || ! is_array($json['daily'] ?? null)
            || ! is_numeric($json['current']['temperature_2m'] ?? null) || ! is_array($json['daily']['time'] ?? null)) {
            return null; // JSON invalide ou schéma inattendu : rien n'est deviné
        }
        $nombre = fn (mixed $v): float|null => is_numeric($v) ? (float) $v : null;
        $c = $json['current'];
        $d = $json['daily'];
        $jours = [];
        foreach (array_slice($d['time'], 0, 7) as $i => $date) {
            if (! is_string($date)) {
                return null;
            }
            $code = $d['weather_code'][$i] ?? null;
            $jours[] = [
                'date' => $date,
                'code' => is_numeric($code) ? (int) $code : null,
                'max' => $nombre($d['temperature_2m_max'][$i] ?? null),
                'min' => $nombre($d['temperature_2m_min'][$i] ?? null),
                'neige' => $nombre($d['snowfall_sum'][$i] ?? null),
            ];
        }
        $code = $c['weather_code'] ?? null;

        return [
            'le' => now()->getTimestamp(),
            'actuel' => [
                'temperature' => $nombre($c['temperature_2m']),
                'ressenti' => $nombre($c['apparent_temperature'] ?? null),
                'humidite' => $nombre($c['relative_humidity_2m'] ?? null),
                'vent' => $nombre($c['wind_speed_10m'] ?? null),
                'iso0' => $nombre($c['freezing_level_height'] ?? null),
                // Mètres dans la réponse ; absente ou négative = indisponible (US-705-N1).
                'neige_sol' => $nombre($c['snow_depth'] ?? null),
                'code' => is_numeric($code) ? (int) $code : null,
            ],
            'jours' => $jours,
        ];
    }
}

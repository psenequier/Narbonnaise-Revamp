<?php

declare(strict_types=1);

namespace App\Domain;

use App\Domain\Equite\RegleEquite;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Paramètres modifiables par les admins (écran Paramètres).
 * Valeurs par défaut : règles d'équité désactivées (0).
 */
final class Parametres
{
    public const DEFAUTS = [
        'equite.delai_maximal_jours' => '0',
        'equite.seuil_reservations' => '0',
        'equite.delai_reduit_jours' => '0',
        'source.calendrier_scolaire' => 'https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/fr-en-calendrier-scolaire/records',
        'source.open_meteo' => 'https://api.open-meteo.com/v1/forecast',
        'station.latitude' => '45.1232',
        'station.longitude' => '5.8773',
        'station.altitude' => '1650',
        'station.bulletin_neige' => 'https://www.chamrousse.com/ouverture-alpin-piste-remontee.html',
        // Dates d'ouverture et de fermeture du domaine, par saison (modifiables dans Paramètres).
        'station.ouverture.2026-2027' => '2026-12-05',
        'station.fermeture.2026-2027' => '2027-04-11',
    ];

    public function lire(string $cle): string
    {
        /** @var array<string, string> $valeurs */
        $valeurs = Cache::remember('parametres', 3600, fn () => DB::table('parametres')->pluck('valeur', 'cle')->all());

        return $valeurs[$cle] ?? self::DEFAUTS[$cle] ?? throw new \InvalidArgumentException("Paramètre inconnu : {$cle}");
    }

    /** Paramètre facultatif : null s'il n'existe ni en base ni par défaut. */
    public function facultatif(string $cle): ?string
    {
        try {
            return $this->lire($cle);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    public function entier(string $cle): int
    {
        return max(0, (int) $this->lire($cle));
    }

    public function regleEquite(): RegleEquite
    {
        return new RegleEquite(
            $this->entier('equite.delai_maximal_jours'),
            $this->entier('equite.seuil_reservations'),
            $this->entier('equite.delai_reduit_jours'),
        );
    }

    public function oublier(): void
    {
        Cache::forget('parametres');
    }
}

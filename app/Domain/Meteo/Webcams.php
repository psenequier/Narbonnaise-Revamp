<?php

declare(strict_types=1);

namespace App\Domain\Meteo;

use App\Domain\Sources\UrlSourceSure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Webcams de la station (US-710, décision du 7 nov. 2026). Skaping n'offre pas d'intégration :
 * sa page est relue côté serveur au plus toutes les 10 minutes, l'adresse de la dernière image
 * est lue dans ses métadonnées (og:image), puis l'image est enregistrée et servie par le site.
 * Les adresses des pages sont fixes (pas des paramètres) : aucune requête ne peut être détournée.
 *
 * @phpstan-type Webcam array{cle: string, nom: string, lieu: string, page: string, image: string|null, heure: string|null, ancienne: bool}
 * @psalm-type Webcam = array{cle: string, nom: string, lieu: string, page: string, image: string|null, heure: string|null, ancienne: bool}
 */
final class Webcams
{
    public const LISTE = [
        'la-croix' => ['nom' => 'La Croix', 'lieu' => 'Sommet · 2 250 m', 'page' => 'https://www.skaping.com/chamrousse/la-croix'],
        'recoin' => ['nom' => 'Le Recoin', 'lieu' => 'Front de neige · 1 650 m', 'page' => 'https://www.skaping.com/chamrousse/recoin/archives'],
    ];

    public const RELECTURE_SECONDES = 600;

    /** Au-delà, l'image est marquée « Image ancienne » (US-710-N1). */
    public const ANCIENNE_HEURES = 2;

    public const TAILLE_MAX = 5 * 1024 * 1024;

    /** Hôtes d'où une image peut venir (US-710-N2). */
    private const HOTES = ['skaping.com', 'skaping.s3.gra.io.cloud.ovh.net'];

    public function __construct(private readonly UrlSourceSure $filtre) {}

    /** @return list<Webcam> */
    public function lire(): array
    {
        $webcams = [];
        foreach (self::LISTE as $cle => $w) {
            if (Cache::add("webcam.{$cle}.frais", 1, self::RELECTURE_SECONDES)) {
                $this->relire($cle, $w['page']); // un échec garde la dernière image
            }
            $fichier = self::fichier($cle);
            /** @var array{fichier: string, heure: string}|null $connue */
            $connue = Cache::get("webcam.{$cle}");
            $heure = $fichier !== null && $connue !== null ? $connue['heure'] : null;
            $webcams[] = [
                'cle' => $cle, 'nom' => $w['nom'], 'lieu' => $w['lieu'], 'page' => $w['page'],
                'image' => $fichier,
                'heure' => $heure,
                'ancienne' => $heure !== null && new DateTimeImmutable($heure) < now()->subHours(self::ANCIENNE_HEURES)->toDateTimeImmutable(),
            ];
        }

        return $webcams;
    }

    /** Chemin de l'image enregistrée d'une webcam, ou null. */
    public static function fichier(string $cle): ?string
    {
        /** @var array{fichier: string, heure: string}|null $connue */
        $connue = Cache::get("webcam.{$cle}");

        return $connue !== null && Storage::disk('local')->exists($connue['fichier']) ? $connue['fichier'] : null;
    }

    private function relire(string $cle, string $page): void
    {
        try {
            $reponse = Http::timeout(8)->connectTimeout(5)
                ->withHeaders(['User-Agent' => 'Chamrousse-reservation/1.0 (site familial, une lecture par 10 minutes)'])
                ->get($page);
            $adresse = $reponse->successful() ? self::adresseImage($reponse->body()) : null;
            if ($adresse === null || ! self::hoteAutorise($adresse)) {
                return;
            }
            $this->filtre->valider($adresse);
            $image = Http::timeout(10)->connectTimeout(5)->withoutRedirecting()->get($adresse);
            $corps = $image->body();
            if (! $image->successful() || $corps === '' || strlen($corps) > self::TAILLE_MAX) {
                return;
            }
            $extension = self::extension($corps);
            if ($extension === null) {
                return; // pas une image : rien n'est repris
            }
            $fichier = "webcams/{$cle}.{$extension}";
            Storage::disk('local')->put($fichier, $corps);
            Cache::forever("webcam.{$cle}", ['fichier' => $fichier, 'heure' => self::heure($adresse)]);
        } catch (Throwable) {
            // Site muet, délai dépassé, adresse refusée : on garde la dernière image.
        }
    }

    /** Adresse de la dernière image, lue dans la balise og:image (ou twitter:image) de la page. */
    public static function adresseImage(string $page): ?string
    {
        foreach (['og:image', 'twitter:image'] as $nom) {
            $motif = '/<meta\b[^>]*\b(?:property|name)\s*=\s*["\']'.preg_quote($nom, '/').'["\'][^>]*>/i';
            if (preg_match($motif, $page, $balise) === 1
                && preg_match('/\bcontent\s*=\s*["\']([^"\']+)["\']/i', $balise[0], $contenu) === 1) {
                return html_entity_decode(trim($contenu[1]), ENT_QUOTES | ENT_HTML5);
            }
        }

        return null;
    }

    /** US-710-N2 : https seulement, et seulement Skaping ou son hébergeur d'images. */
    public static function hoteAutorise(string $adresse): bool
    {
        if (parse_url($adresse, PHP_URL_SCHEME) !== 'https') {
            return false;
        }
        $hote = strtolower((string) parse_url($adresse, PHP_URL_HOST));
        foreach (self::HOTES as $autorise) {
            if ($hote === $autorise || str_ends_with($hote, '.'.$autorise)) {
                return true;
            }
        }

        return false;
    }

    /** JPEG ou PNG reconnu à son contenu, jamais à l'en-tête annoncé. */
    public static function extension(string $corps): ?string
    {
        $info = @getimagesizefromstring($corps);
        if ($info === false) {
            return null;
        }

        return match ($info[2]) {
            IMAGETYPE_JPEG => 'jpg',
            IMAGETYPE_PNG => 'png',
            default => null,
        };
    }

    /** Heure de la prise de vue, lue dans l'adresse (…/2026/11/07/large/14-50.jpg), sinon maintenant. */
    public static function heure(string $adresse): string
    {
        $paris = new DateTimeZone('Europe/Paris');
        if (preg_match('#/(\d{4})/(\d{2})/(\d{2})/(?:[a-z]+/)?(\d{2})-(\d{2})\.(?:jpe?g|png)$#i', (string) parse_url($adresse, PHP_URL_PATH), $m) === 1
            && checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60) {
            return (new DateTimeImmutable("{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:00", $paris))->format(DATE_ATOM);
        }

        return now($paris)->toDateTimeImmutable()->format(DATE_ATOM);
    }
}

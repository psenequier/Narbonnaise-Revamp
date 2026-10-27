<?php

declare(strict_types=1);

namespace App\Domain\Vacances;

use App\Domain\Parametres;
use App\Domain\Sources\UrlSourceSure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Calendrier scolaire officiel (data.education.gouv.fr, ADR 007, ADR 011) :
 * vacances de Noël et d'hiver de la zone A pour une année scolaire.
 * Toute réponse douteuse lève SourceEnEchec ; rien n'est deviné.
 */
final class SourceCalendrierScolaire
{
    public const DELAI_SECONDES = 10;

    /** Académie de l'appartement, préférée quand la source détaille par académie. */
    private const ACADEMIE = 'Grenoble';

    public function __construct(
        private readonly Parametres $parametres,
        private readonly UrlSourceSure $filtre,
    ) {}

    /**
     * @return array<'noel'|'hiver', array{debut: string, reprise: string}> dates brutes de la source
     */
    public function vacances(string $annee): array
    {
        try {
            $url = $this->filtre->valider($this->parametres->lire('source.calendrier_scolaire'));
        } catch (InvalidArgumentException $e) {
            throw new SourceEnEchec('Adresse de la source refusée : '.$e->getMessage());
        }

        try {
            $reponse = Http::timeout(self::DELAI_SECONDES)->connectTimeout(self::DELAI_SECONDES)->acceptJson()
                ->get($url, ['where' => 'annee_scolaire="'.$annee.'"', 'limit' => 100]);
        } catch (ConnectionException $e) {
            throw new SourceEnEchec('Source indisponible ou délai dépassé ('.self::DELAI_SECONDES.' s).');
        }
        if (! $reponse->successful()) {
            throw new SourceEnEchec('La source a répondu avec le code HTTP '.$reponse->status().'.');
        }

        $json = json_decode($reponse->body(), true);
        if (! is_array($json)) {
            throw new SourceEnEchec('La source a renvoyé un JSON invalide.');
        }
        if (! isset($json['results']) || ! is_array($json['results'])) {
            throw new SourceEnEchec('Schéma inattendu : pas de liste « results ».');
        }

        /** @var array<'noel'|'hiver', array<string, array{debut: string, reprise: string, academie: bool}>> $trouvees */
        $trouvees = [];
        foreach ($json['results'] as $r) {
            if (! is_array($r)) {
                throw new SourceEnEchec('Schéma inattendu : enregistrement illisible.');
            }
            foreach (['description', 'start_date', 'end_date', 'zones'] as $champ) {
                if (! isset($r[$champ]) || ! is_string($r[$champ])) {
                    throw new SourceEnEchec("Schéma inattendu : champ « {$champ} » absent.");
                }
            }
            if (trim($r['zones']) !== 'Zone A') {
                continue; // zones B et C ignorées (US-401-N2)
            }
            if (isset($r['population']) && $r['population'] === 'Enseignants') {
                continue;
            }
            $description = mb_strtolower($r['description']);
            $nom = match (true) {
                str_contains($description, 'noël') || str_contains($description, 'noel') => 'noel',
                str_contains($description, 'hiver') => 'hiver',
                default => null,
            };
            if ($nom === null) {
                continue;
            }
            $academie = isset($r['location']) && $r['location'] === self::ACADEMIE;
            $trouvees[$nom][$r['start_date'].'|'.$r['end_date']] = [
                'debut' => $r['start_date'], 'reprise' => $r['end_date'],
                'academie' => $academie || ($trouvees[$nom][$r['start_date'].'|'.$r['end_date']]['academie'] ?? false),
            ];
        }

        $vacances = [];
        foreach ($trouvees as $nom => $variantes) {
            $retenues = array_filter($variantes, fn ($v) => $v['academie']) ?: $variantes;
            if (count($retenues) > 1) {
                throw new SourceEnEchec("Dates contradictoires dans la source pour les vacances « {$nom} ».");
            }
            $v = array_values($retenues)[0];
            if (strtotime($v['reprise']) === false || strtotime($v['debut']) === false || strtotime($v['reprise']) <= strtotime($v['debut'])) {
                throw new SourceEnEchec("Dates incohérentes dans la source pour les vacances « {$nom} » (fin avant début).");
            }
            $vacances[$nom] = ['debut' => $v['debut'], 'reprise' => $v['reprise']];
        }

        return $vacances;
    }
}

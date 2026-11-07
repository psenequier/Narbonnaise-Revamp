<?php

declare(strict_types=1);

namespace App\Domain\Statistiques;

use App\Domain\Parametres;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Statistiques d'occupation (F11, US-701 à US-703). Ne lit que la vue v_occupation :
 * aucune donnée de membre ni de lignée (ADR 012, vérifié par un test d'architecture).
 *
 * Une période se compte en nuits (et journées) du premier au dernier jour inclus.
 * Une saison va de la nuit du jour d'ouverture de la station à la nuit de la veille
 * de la fermeture ; sans dates saisies, du 1er décembre au 30 avril (« dates approchées »).
 */
final class Statistiques
{
    public const TYPES = ['ouverte', 'exclusive', 'attribuee'];

    public function __construct(private readonly Parametres $parametres) {}

    /** @return array{debut: DateTimeImmutable, fin: DateTimeImmutable, approchee: bool} fin = dernière nuit comptée */
    public function bornesSaison(string $saison): array
    {
        $a = (int) substr($saison, 0, 4);
        $ouverture = $this->parametres->facultatif("station.ouverture.{$saison}");
        $fermeture = $this->parametres->facultatif("station.fermeture.{$saison}");
        $approchee = $ouverture === null || $fermeture === null;

        return [
            'debut' => new DateTimeImmutable($approchee ? "{$a}-12-01" : $ouverture),
            'fin' => (new DateTimeImmutable($approchee ? ($a + 1).'-04-30' : $fermeture))->modify('-1 day'),
            'approchee' => $approchee,
        ];
    }

    /** @return array{debut: DateTimeImmutable, fin: DateTimeImmutable, approchee: bool} */
    public static function bornesAnnee(int $annee): array
    {
        return ['debut' => new DateTimeImmutable("{$annee}-01-01"), 'fin' => new DateTimeImmutable("{$annee}-12-31"), 'approchee' => false];
    }

    /**
     * @return array{
     *   jours: int, nuits: int, journees: int, nuitees_personnes: int, samedis: int, samedis_occupes: int,
     *   par_type: array<string, int>,
     *   mois: list<array{mois: string, ouverte: int, exclusive: int, attribuee: int, total: int, jours: int, libres: int}>
     * }
     */
    public function periode(DateTimeImmutable $debut, DateTimeImmutable $fin): array
    {
        $lignes = DB::table('v_occupation')
            ->whereBetween('date', [$debut->format('Y-m-d'), $fin->format('Y-m-d')])
            ->where('etat', '!=', 'libre')
            ->get(['date', 'type', 'etat', 'personnes']);

        /** @var array<string, array{mois: string, ouverte: int, exclusive: int, attribuee: int, total: int, jours: int, libres: int}> $mois */
        $mois = [];
        for ($m = $debut->modify('first day of this month'); $m <= $fin; $m = $m->modify('+1 month')) {
            // Nuits du mois comprises dans la période (27 pour décembre dans une saison ouverte le 5).
            $premier = max($m, $debut);
            $dernier = min($m->modify('last day of this month'), $fin);
            $mois[$m->format('Y-m')] = ['mois' => $m->format('Y-m'), 'ouverte' => 0, 'exclusive' => 0, 'attribuee' => 0, 'total' => 0,
                'jours' => (int) $premier->diff($dernier)->days + 1, 'libres' => 0];
        }
        $parType = array_fill_keys(self::TYPES, 0);
        $nuits = $journees = $personnes = $samedisOccupes = 0;
        foreach ($lignes as $l) {
            $date = substr((string) $l->date, 0, 10);
            if ($l->type === 'J') {
                $journees++;

                continue;
            }
            $etat = match ((string) $l->etat) {
                'exclusive' => 'exclusive', 'attribuee' => 'attribuee', default => 'ouverte',
            };
            $nuits++;
            $personnes += (int) $l->personnes;
            $parType[$etat]++;
            $cle = substr($date, 0, 7);
            if (isset($mois[$cle])) {
                $mois[$cle][$etat] += 1;
                $mois[$cle]['total'] += 1;
            }
            if ((new DateTimeImmutable($date))->format('N') === '6') {
                $samedisOccupes++;
            }
        }

        foreach ($mois as $cle => $m) {
            $m['libres'] = max(0, $m['jours'] - $m['total']); // US-702-P4
            $mois[$cle] = $m;
        }
        $jours = (int) $debut->diff($fin)->days + 1;
        $samedis = 0;
        for ($j = $debut; $j <= $fin; $j = $j->modify('+1 day')) {
            $samedis += $j->format('N') === '6' ? 1 : 0;
        }

        return [
            'jours' => $jours, 'nuits' => $nuits, 'journees' => $journees, 'nuitees_personnes' => $personnes,
            'samedis' => $samedis, 'samedis_occupes' => $samedisOccupes, 'par_type' => $parType, 'mois' => array_values($mois),
        ];
    }

    /** Taux arrondi à l'entier ; 0 % pour une période vide, sans erreur (US-701-N4). */
    public static function taux(int $occupes, int $total): int
    {
        return $total === 0 ? 0 : (int) round(100 * $occupes / $total);
    }

    /**
     * US-703 : toutes les saisons depuis la première nuit occupée connue, jusqu'à la saison en cours.
     *
     * @return list<array{saison: string, taux: int, nuits: int, jours: int, approchee: bool, source: string}>
     */
    public function saisons(DateTimeImmutable $aujourdhui): array
    {
        $premiere = DB::table('v_occupation')->where('etat', '!=', 'libre')->min('date');
        if ($premiere === null) {
            return [];
        }
        $premiere = new DateTimeImmutable(substr((string) $premiere, 0, 10));
        $debut = self::anneeDeSaison($premiere);
        $derniere = max($debut, self::anneeDeSaison($aujourdhui));

        $saisons = [];
        for ($a = $debut; $a <= $derniere; $a++) {
            $libelle = $a.'-'.($a + 1);
            $bornes = $this->bornesSaison($libelle);
            if ($bornes['fin'] < $premiere) {
                continue; // US-703-N2 : saison antérieure à la première réservation reprise
            }
            $p = $this->periode($bornes['debut'], $bornes['fin']);
            $sources = DB::table('v_occupation')
                ->whereBetween('date', [$bornes['debut']->format('Y-m-d'), $bornes['fin']->format('Y-m-d')])
                ->selectRaw('COALESCE(MAX(ancien_site), 0) AS ancien, COALESCE(MAX(nouveau_site), 0) AS nouveau')
                ->first();
            $ancien = (int) ($sources->ancien ?? 0) === 1;
            $nouveau = (int) ($sources->nouveau ?? 0) === 1;
            $saisons[] = [
                'saison' => $libelle,
                'taux' => self::taux($p['nuits'], $p['jours']),
                'nuits' => $p['nuits'],
                'jours' => $p['jours'],
                'approchee' => $bornes['approchee'],
                'source' => $ancien && $nouveau ? 'ancien et nouveau site' : ($ancien ? 'ancien site' : 'nouveau site'),
            ];
        }

        return $saisons;
    }

    /** Une saison d'hiver « 2026-2027 » commence en septembre 2026 : de septembre à août. */
    public static function anneeDeSaison(DateTimeImmutable $jour): int
    {
        return (int) $jour->format('Y') - ((int) $jour->format('n') < 9 ? 1 : 0);
    }
}

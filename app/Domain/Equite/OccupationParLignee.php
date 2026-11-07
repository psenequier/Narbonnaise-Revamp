<?php

declare(strict_types=1);

namespace App\Domain\Equite;

use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Occupation par lignée (US-711, ADR 019), pour les seuls comptes désignés : la vérification
 * d'accès est faite par l'appelant (porte « statistiques-lignees »). Volontairement hors du
 * module Statistiques, qui ne lit que v_occupation (ADR 012).
 *
 * Une nuit de vacances compte pour la lignée attribuée ; une autre nuit, pour la lignée de
 * l'auteur de chaque réservation active (exclusive l'emporte sur ouverte). Jamais par personne.
 *
 * @phpstan-type Ligne array{nom: string, rang: int, ouverte: int, exclusive: int, attribuee: int, total: int, personnes: int, reservations: int}
 * @psalm-type Ligne = array{nom: string, rang: int, ouverte: int, exclusive: int, attribuee: int, total: int, personnes: int, reservations: int}
 */
final class OccupationParLignee
{
    /** @return list<Ligne> une ligne par lignée (ordre de création), puis « Sans lignée » s'il y a lieu */
    public function periode(DateTimeImmutable $debut, DateTimeImmutable $fin): array
    {
        $bornes = [$debut->format('Y-m-d'), $fin->format('Y-m-d')];

        /** @var array<int|string, Ligne> $lignes */
        $lignes = [];
        $rang = 0;
        foreach (DB::table('lignees')->orderBy('id')->get(['id', 'nom']) as $l) {
            $lignes[(int) $l->id] = self::vide((string) $l->nom, $rang++);
        }
        $sans = self::vide('Sans lignée', -1);

        // Nuits attribuées (vacances).
        $attribuees = DB::table('attributions as a')
            ->join('creneaux as c', 'c.id', '=', 'a.creneau_id')
            ->where('c.type', 'N')->whereBetween('c.date', $bornes)
            ->get(['a.lignee_id', 'c.id as creneau']);
        $nuitsAttribuees = [];
        foreach ($attribuees as $a) {
            $nuitsAttribuees[(int) $a->creneau] = true;
            if (isset($lignes[(int) $a->lignee_id])) {
                $lignes[(int) $a->lignee_id]['attribuee']++;
            }
        }

        // Réservations actives : nuits hors vacances, nuitées-personnes et nombre de réservations.
        $rangs = DB::table('reservation_creneau as rc')
            ->join('reservations as r', 'r.id', '=', 'rc.reservation_id')
            ->join('creneaux as c', 'c.id', '=', 'rc.creneau_id')
            ->leftJoin('users as u', 'u.id', '=', 'r.user_id')
            ->whereNull('r.annulee_le')
            ->where('c.type', 'N')->whereBetween('c.date', $bornes)
            ->get(['u.lignee_id', 'r.id as reservation', 'r.mode', 'r.nombre_personnes', 'c.id as creneau']);
        /** @var array<string, array<int, string>> $modes cle de lignée → créneau → mode retenu */
        $modes = [];
        /** @var array<string, array<int, true>> $reservations */
        $reservations = [];
        foreach ($rangs as $r) {
            $cle = $r->lignee_id !== null && isset($lignes[(int) $r->lignee_id]) ? (string) (int) $r->lignee_id : 'sans';
            $reservations[$cle][(int) $r->reservation] = true;
            if ($cle === 'sans') {
                $sans['personnes'] += (int) $r->nombre_personnes;
            } else {
                $lignes[(int) $cle]['personnes'] += (int) $r->nombre_personnes;
            }
            if (isset($nuitsAttribuees[(int) $r->creneau])) {
                continue;
            }
            $mode = $r->mode === 'exclusive' ? 'exclusive' : 'ouverte';
            if (($modes[$cle][(int) $r->creneau] ?? null) !== 'exclusive') {
                $modes[$cle][(int) $r->creneau] = $mode;
            }
        }
        foreach ($modes as $cle => $creneaux) {
            $compte = array_count_values($creneaux);
            if ($cle === 'sans') {
                $sans['ouverte'] = $compte['ouverte'] ?? 0;
                $sans['exclusive'] = $compte['exclusive'] ?? 0;
            } else {
                $lignes[(int) $cle]['ouverte'] = $compte['ouverte'] ?? 0;
                $lignes[(int) $cle]['exclusive'] = $compte['exclusive'] ?? 0;
            }
        }
        foreach ($reservations as $cle => $ids) {
            if ($cle === 'sans') {
                $sans['reservations'] = count($ids);
            } else {
                $lignes[(int) $cle]['reservations'] = count($ids);
            }
        }

        $resultat = [];
        foreach ([...array_values($lignes), $sans] as $l) {
            $l['total'] = $l['ouverte'] + $l['exclusive'] + $l['attribuee'];
            if ($l['rang'] >= 0 || $l['total'] > 0 || $l['reservations'] > 0) {
                $resultat[] = $l;
            }
        }

        return $resultat;
    }

    /** @return Ligne */
    private static function vide(string $nom, int $rang): array
    {
        return ['nom' => $nom, 'rang' => $rang, 'ouverte' => 0, 'exclusive' => 0, 'attribuee' => 0, 'total' => 0, 'personnes' => 0, 'reservations' => 0];
    }
}

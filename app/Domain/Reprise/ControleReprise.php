<?php

declare(strict_types=1);

namespace App\Domain\Reprise;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * US-803 : rapport de contrôle de la reprise. Il recompte, année par année,
 * côté GRR (selon les règles de l'import) et côté nouveau site, et montre
 * 20 réservations tirées au hasard. Il ne passe « validé » que sans aucun écart.
 */
final class ControleReprise
{
    public const ECHANTILLON = 20;

    public function __construct(private readonly BaseGrr $grr) {}

    /** @return array{comptes: array{grr: int, repris: int, anomalies: int, ecart: bool}, annees: array<int|string, array<string, mixed>>, echantillon: list<array<string, string>>, valide: bool} */
    public function rapport(): array
    {
        $paris = new DateTimeZone('Europe/Paris');
        $comptesGrr = $this->grr->comptes();
        /** @var array<string, int> $parEmail */
        $parEmail = [];
        foreach ($comptesGrr as $u) {
            $e = Str::lower(trim((string) $u->email));
            $parEmail[$e] = ($parEmail[$e] ?? 0) + 1;
        }
        $anomalies = $comptesGrr->filter(function ($u) use ($parEmail) {
            $email = Str::lower(trim((string) $u->email));

            return $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ($parEmail[$email] ?? 0) > 1;
        })->count();
        /** @var array<string, int> $repris */
        $repris = DB::table('users')->whereNotNull('grr_login')->pluck('id', 'grr_login')->map(fn ($id) => (int) $id)->all();
        $comptes = ['grr' => $comptesGrr->count(), 'repris' => count($repris), 'anomalies' => $anomalies];
        $comptes['ecart'] = $comptes['grr'] !== $comptes['repris'] + $comptes['anomalies'];

        // Côté GRR : les entrées que l'import doit reprendre comme réservations (mêmes règles que l'import).
        $attendues = [];
        foreach ($this->grr->entrees($repris ?: ['' => 0]) as $e) {
            if ($e['classe'] !== BaseGrr::RESERVATION) {
                continue;
            }
            $attendues[$e['id']] = [
                'annee' => (new DateTimeImmutable('@'.$e['debut']))->setTimezone($paris)->format('Y'),
                'nuits' => count(array_filter($e['creneaux'], fn ($c) => $c->type->value === 'N')),
                'login' => $e['login'], 'debut' => $e['debut'], 'fin' => $e['fin'],
            ];
        }

        // Côté nouveau site : les réservations reprises, rattachées à l'année de leur entrée GRR.
        /** @var array<int, int> $nuitsSite */
        $nuitsSite = DB::table('reservations as r')
            ->leftJoin('reservation_creneau as rc', 'rc.reservation_id', '=', 'r.id')
            ->leftJoin('creneaux as c', 'c.id', '=', 'rc.creneau_id')
            ->whereIn('r.grr_id', array_keys($attendues) ?: [0])
            ->groupBy('r.grr_id')
            ->pluck(DB::raw("SUM(c.type = 'N')"), 'r.grr_id')
            ->map(fn ($n) => (int) $n)->all();

        $annees = [];
        foreach ($attendues as $id => $a) {
            $annees[$a['annee']] ??= ['grr_reservations' => 0, 'site_reservations' => 0, 'grr_nuits' => 0, 'site_nuits' => 0];
            $annees[$a['annee']]['grr_reservations']++;
            $annees[$a['annee']]['grr_nuits'] += $a['nuits'];
            if (isset($nuitsSite[$id])) {
                $annees[$a['annee']]['site_reservations']++;
                $annees[$a['annee']]['site_nuits'] += $nuitsSite[$id];
            }
        }
        ksort($annees);
        foreach ($annees as &$a) {
            $a['ecart'] = $a['grr_reservations'] !== $a['site_reservations'] || $a['grr_nuits'] !== $a['site_nuits'];
        }
        unset($a);

        $rapport = [
            'comptes' => $comptes,
            'annees' => $annees,
            'echantillon' => $this->echantillon($attendues),
            'valide' => ! $comptes['ecart'] && collect($annees)->every(fn ($a) => ! $a['ecart']),
        ];
        DB::table('reprise_rapports')->insert([
            'type' => 'controle', 'valide' => $rapport['valide'],
            'contenu' => json_encode($rapport, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        return $rapport;
    }

    /**
     * @param  array<int, array{annee: string, nuits: int, login: string, debut: int, fin: int}>  $attendues
     * @return list<array<string, string>>
     */
    private function echantillon(array $attendues): array
    {
        if ($attendues === []) {
            return [];
        }
        $ids = (array) array_rand($attendues, min(self::ECHANTILLON, count($attendues)));
        $paris = new DateTimeZone('Europe/Paris');

        $lignes = [];
        foreach ($ids as $id) {
            $a = $attendues[$id];
            $r = DB::table('reservations as r')->leftJoin('users as u', 'u.id', '=', 'r.user_id')
                ->where('r.grr_id', $id)->first(['r.id', 'r.mode', 'u.prenom', 'u.nom']);
            $creneaux = $r ? DB::table('reservation_creneau as rc')->join('creneaux as c', 'c.id', '=', 'rc.creneau_id')
                ->where('rc.reservation_id', $r->id)->orderBy('c.date')->orderBy('c.type')
                ->get(['c.date', 'c.type'])->map(fn ($c) => $c->date.' '.$c->type)->implode(', ') : '';
            $lignes[] = [
                'grr' => "n° {$id} · {$a['login']} · du ".(new DateTimeImmutable('@'.$a['debut']))->setTimezone($paris)->format('d/m/Y H:i')
                    .' au '.(new DateTimeImmutable('@'.$a['fin']))->setTimezone($paris)->format('d/m/Y H:i'),
                'site' => $r ? "{$r->prenom} {$r->nom} · {$r->mode} · {$creneaux}" : 'ABSENTE',
            ];
        }

        return $lignes;
    }
}

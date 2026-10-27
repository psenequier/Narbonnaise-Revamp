<?php

use App\Domain\Calendrier\ControleCoherence;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/*
 * US-308 : de vrais processus PHP, chacun avec sa connexion MySQL, lancés à la
 * même milliseconde. Le verrou SELECT … FOR UPDATE (ADR 006) doit laisser passer
 * une seule réservation exclusive. La CI joue ce test sous MySQL 8.0 et 8.4.
 */

beforeEach(function () {
    creerCreneaux('2026-11-01', '2026-11-30');
});

/**
 * @param  list<array{0: User, 1: string, 2: list<string>}>  $tentatives  membre, mode, créneaux
 * @return list<string> « ok », « refus » ou « erreur : … », dans l'ordre des tentatives
 */
function enParallele(array $tentatives): array
{
    $db = config('database.connections.'.config('database.default'));
    $env = array_merge(getenv(), [
        'APP_ENV' => 'testing', 'APP_KEY' => (string) config('app.key'),
        'DB_CONNECTION' => (string) config('database.default'),
        'DB_HOST' => (string) $db['host'], 'DB_PORT' => (string) $db['port'],
        'DB_DATABASE' => (string) $db['database'], 'DB_USERNAME' => (string) $db['username'], 'DB_PASSWORD' => (string) $db['password'],
        'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array',
    ]);
    $depart = microtime(true) + 2.0; // laisse à chaque enfant le temps de démarrer Laravel

    $processus = [];
    foreach ($tentatives as [$membre, $mode, $cles]) {
        $commande = [PHP_BINARY, __DIR__.'/enfant.php', (string) $membre->id, $mode, implode(',', $cles), (string) $depart];
        // nosemgrep: php.lang.security.exec-use.exec-use
        $p = proc_open($commande, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubes, base_path(), $env);
        $processus[] = [$p, $tubes];
    }

    $resultats = [];
    foreach ($processus as [$p, $tubes]) {
        $sortie = trim((string) stream_get_contents($tubes[1]));
        $erreurs = trim((string) stream_get_contents($tubes[2]));
        fclose($tubes[1]);
        fclose($tubes[2]);
        proc_close($p);
        $resultats[] = $sortie !== '' ? $sortie : 'erreur : '.$erreurs;
    }

    return $resultats;
}

function modesActifs(string $date, string $type): array
{
    return DB::table('reservation_creneau as rc')
        ->join('reservations as r', 'r.id', '=', 'rc.reservation_id')
        ->join('creneaux as c', 'c.id', '=', 'rc.creneau_id')
        ->where('c.date', $date)->where('c.type', $type)->whereNull('r.annulee_le')
        ->pluck('r.mode')->sort()->values()->all();
}

it('US-308-P1 crée les deux réservations ouvertes envoyées en parallèle', function () {
    [$a, $b] = User::factory()->count(2)->create()->all();

    $resultats = enParallele([[$a, 'ouverte', ['2026-11-14-N']], [$b, 'ouverte', ['2026-11-14-N']]]);

    expect($resultats)->toBe(['ok', 'ok'])
        ->and(modesActifs('2026-11-14', 'N'))->toBe(['ouverte', 'ouverte']);
});

it('US-308-N1 ne crée qu\'une des deux réservations exclusives envoyées en parallèle', function () {
    [$a, $b] = User::factory()->count(2)->create()->all();

    $resultats = enParallele([
        [$a, 'exclusive', ['2026-11-14-N', '2026-11-15-J']],
        [$b, 'exclusive', ['2026-11-15-J', '2026-11-15-N']],
    ]);

    sort($resultats);
    expect($resultats)->toBe(['ok', 'refus'])
        ->and(modesActifs('2026-11-15', 'J'))->toBe(['exclusive']);
});

it('US-308-N2 ne crée qu\'une réservation entre une exclusive et une ouverte envoyées en parallèle', function () {
    [$a, $b] = User::factory()->count(2)->create()->all();

    $resultats = enParallele([[$a, 'exclusive', ['2026-11-14-N']], [$b, 'ouverte', ['2026-11-14-N']]]);

    sort($resultats);
    expect($resultats)->toBe(['ok', 'refus'])
        ->and(modesActifs('2026-11-14', 'N'))->toHaveCount(1);
});

it('US-308-N3 garde des créneaux cohérents après 100 tentatives concurrentes aléatoires', function () {
    $membres = User::factory()->count(10)->create()->all();
    mt_srand(308); // tirage reproductible
    $cles = [];
    foreach (range(10, 13) as $j) {
        $cles[] = "2026-11-{$j}-J";
        $cles[] = "2026-11-{$j}-N";
    }

    $resultats = [];
    for ($vague = 0; $vague < 5; $vague++) {
        $tentatives = [];
        for ($i = 0; $i < 20; $i++) {
            $debut = mt_rand(0, count($cles) - 2);
            $tentatives[] = [$membres[mt_rand(0, 9)], mt_rand(0, 2) === 0 ? 'exclusive' : 'ouverte', array_slice($cles, $debut, mt_rand(1, 2))];
        }
        $resultats = [...$resultats, ...enParallele($tentatives)];
    }

    expect($resultats)->toHaveCount(100)
        ->and(array_filter($resultats, fn ($r) => ! in_array($r, ['ok', 'refus'], true)))->toBe([])
        ->and(app(ControleCoherence::class)->anomalies())->toBe([]);
});

<?php

/*
 * Processus enfant des tests de concurrence (US-308) : démarre Laravel, attend
 * l'heure de départ commune, tente une réservation et écrit « ok » ou « refus ».
 * Arguments : id du membre, mode, créneaux séparés par des virgules, heure de départ.
 */

use App\Domain\Calendrier\Creneau;
use App\Domain\Calendrier\TypeCreneau;
use App\Domain\Reservations\ReservationRefusee;
use App\Domain\Reservations\ServiceReservation;
use App\Models\User;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

[, $userId, $mode, $cles, $depart] = $argv;
$demandes = array_map(
    fn (string $c) => new Creneau(new DateTimeImmutable(substr($c, 0, 10)), TypeCreneau::from(substr($c, -1))),
    explode(',', $cles),
);
$membre = User::findOrFail((int) $userId);

while (microtime(true) < (float) $depart) {
    usleep(200);
}

try {
    app(ServiceReservation::class)->reserver($membre, $demandes, $mode, null, new DateTimeImmutable('2026-11-02 10:00:00'), 2);
    echo 'ok';
} catch (ReservationRefusee) {
    echo 'refus';
} catch (Throwable $e) {
    echo 'erreur : '.get_class($e).' '.$e->getMessage();
}

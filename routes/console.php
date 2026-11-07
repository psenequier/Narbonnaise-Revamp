<?php

use App\Domain\Surveillance\AlertesPanne;
use Illuminate\Support\Facades\Schedule;

// Une seule cron OVH, horaire : php artisan schedule:run (ADR 005).
// Chaque tâche est idempotente : un passage manqué est rattrapé au suivant.
// Attention : OVH désactive la cron après 10 échecs consécutifs. N'inscrire
// ici qu'une commande qui existe et qui est couverte par un test.

// US-910-P2 : chaque passage est noté ; la page de santé et les visites alertent après 2 h de silence.
Schedule::call(fn () => AlertesPanne::battement())->name('battement')->hourly();

// US-909 : sauvegarde chiffrée de la base, 30 jours gardés (config/backup.php) ;
// un échec prévient les admins (écouteurs dans AppServiceProvider).
Schedule::command('backup:run --only-db')->dailyAt('03:00');
Schedule::command('backup:clean')->dailyAt('03:30');
Schedule::command('backup:monitor')->dailyAt('05:00');

Schedule::command('emails:envoyer --max=150')->hourly()->withoutOverlapping(55);
Schedule::command('creneaux:verifier')->dailyAt('04:15');

Schedule::command('vacances:verifier')->monthlyOn(1, '04:00')->timezone('Europe/Paris');
Schedule::command('attributions:rappeler')->dailyAt('08:00')->timezone('Europe/Paris');

// RGPD (RFC) : les adresses IP du journal sont effacées après 12 mois.
Schedule::command('journal:purger-ip')->dailyAt('04:30');

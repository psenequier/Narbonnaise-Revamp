<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// Une seule cron OVH, horaire : php artisan schedule:run (ADR 005).
// Chaque tâche est idempotente : un passage manqué est rattrapé au suivant.
// Attention : OVH désactive la cron après 10 échecs consécutifs. N'inscrire
// ici qu'une commande qui existe et qui est couverte par un test.

Schedule::call(fn () => Cache::forever('planificateur.dernier_passage', now()))
    ->name('battement')->hourly();

Schedule::command('backup:run --only-db')->dailyAt('03:00');
Schedule::command('backup:clean')->dailyAt('03:30');

// À ajouter avec leurs commandes (plan de dev) :
// emails:envoyer --max=150   hourly, withoutOverlapping(55)
// vacances:verifier          monthlyOn(1, '04:00')
// attributions:rappeler      dailyAt('08:00'), actif en octobre seulement
// journal:purger-ip          monthlyOn(2, '04:00')

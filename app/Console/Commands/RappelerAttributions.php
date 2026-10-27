<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Emails\BoiteEnvoi;
use App\Domain\Vacances\SuiviAttributions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * US-406 : le 1er septembre, le 1er octobre et le 15 octobre, chaque admin est
 * relancé tant que des créneaux de vacances restent sans lignée (objectif : 1er novembre).
 */
final class RappelerAttributions extends Command
{
    public const DATES = ['09-01', '10-01', '10-15'];

    protected $signature = 'attributions:rappeler';

    protected $description = 'Relance les admins si des vacances restent à attribuer';

    public function handle(SuiviAttributions $suivi, BoiteEnvoi $boite): int
    {
        $aujourdhui = now('Europe/Paris');
        if (! in_array($aujourdhui->format('m-d'), self::DATES, true)) {
            $this->info('Pas de relance aujourd\'hui.');

            return self::SUCCESS;
        }
        $restants = $suivi->restants();
        if ($restants === 0) {
            $this->info('Toutes les vacances sont attribuées.');

            return self::SUCCESS;
        }
        // Une seule relance par jour, même si la tâche tourne plusieurs fois.
        if (! Cache::add('attributions:rappel:'.$aujourdhui->format('Y-m-d'), true, now()->addDays(2))) {
            return self::SUCCESS;
        }

        foreach (BoiteEnvoi::adressesAdmins() as $admin) {
            $boite->deposer('admin.rappel_attributions', [$admin], [], [
                'intro' => "Il reste {$restants} créneau(x) de vacances sans lignée. Objectif : tout attribuer avant le 1er novembre.",
                'lignes' => [],
                'lien' => route('admin.vacances'),
            ]);
        }
        $boite->envoyer();
        $this->info("Relance envoyée : {$restants} créneau(x) à attribuer.");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Calendrier\ControleCoherence;
use App\Domain\Emails\BoiteEnvoi;
use Illuminate\Console\Command;

/** US-912 : contrôle quotidien de cohérence. Alerte les admins, ne corrige rien. */
final class VerifierCreneaux extends Command
{
    protected $signature = 'creneaux:verifier';

    protected $description = 'Contrôle la cohérence des créneaux à venir et alerte les admins';

    public function handle(ControleCoherence $controle, BoiteEnvoi $boite): int
    {
        $anomalies = $controle->anomalies();
        if ($anomalies === []) {
            $this->info('Aucune anomalie.');

            return self::SUCCESS;
        }

        foreach ($anomalies as $a) {
            $this->warn($a);
        }
        $admins = BoiteEnvoi::adressesAdmins();
        if ($admins !== []) {
            $boite->deposer('admin.anomalies', $admins, [], ['anomalies' => $anomalies]);
            $boite->envoyer();
        }

        return self::FAILURE;
    }
}

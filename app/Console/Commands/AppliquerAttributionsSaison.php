<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reprise\AttributionsReprises;
use Illuminate\Console\Command;

/** Saison en cours : les attributions GRR dont la lignée est reconnue deviennent des plages. */
final class AppliquerAttributionsSaison extends Command
{
    protected $signature = 'grr:appliquer-saison';

    protected $description = 'Applique les attributions GRR reconnues de la saison en cours (année ouverte, un admin actif requis)';

    public function handle(AttributionsReprises $reprises): int
    {
        $this->info($reprises->appliquerSaison().' attribution(s) de la saison appliquée(s) ; les autres restent à valider.');

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RGPD (RFC, ADR 015) : chaque nuit, efface l'adresse IP des lignes du journal de plus de 12 mois.
 * Seule écriture permise sur le journal : l'action, l'auteur et la date restent.
 */
final class PurgerIpJournal extends Command
{
    protected $signature = 'journal:purger-ip';

    protected $description = 'Efface les adresses IP du journal de plus de 12 mois';

    public function handle(): int
    {
        // Requête directe : le modèle Journal refuse toute modification (ajout seul).
        $effacees = DB::table('journal')
            ->whereNotNull('ip')
            ->where('cree_le', '<', now()->subMonths(12))
            ->update(['ip' => null]);

        $this->info("{$effacees} adresse(s) IP effacée(s).");

        return self::SUCCESS;
    }
}

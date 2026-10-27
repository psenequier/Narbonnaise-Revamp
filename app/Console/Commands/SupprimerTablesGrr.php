<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reprise\SuppressionGrr;
use Illuminate\Console\Command;

/** US-805-N2 : suppression des tables GRR, refusée sans contrôle validé et export chiffré. */
final class SupprimerTablesGrr extends Command
{
    protected $signature = 'grr:supprimer-tables {--confirmer : supprime réellement (sinon, liste seulement)}';

    protected $description = 'Supprime les tables GRR, seulement après un contrôle validé et un export chiffré intact';

    public function handle(SuppressionGrr $suppression): int
    {
        $refus = $suppression->refus();
        if ($refus !== []) {
            foreach ($refus as $raison) {
                $this->error($raison);
            }

            return self::FAILURE;
        }

        if (! $this->option('confirmer')) {
            $this->info('Suppression permise. Tables concernées : '.implode(', ', $suppression->tables()).'. Relancez avec --confirmer.');

            return self::SUCCESS;
        }

        $this->info(count($suppression->supprimer()).' table(s) GRR supprimée(s).');

        return self::SUCCESS;
    }
}

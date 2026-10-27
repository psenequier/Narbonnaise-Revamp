<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Vacances\PeriodesScolaires;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

/** US-401 : le 1er de chaque mois, relit le calendrier scolaire officiel (année en cours et suivante). */
final class VerifierVacances extends Command
{
    protected $signature = 'vacances:verifier';

    protected $description = 'Relit les vacances de Noël et de février (zone A) et ouvre les années complètes';

    public function handle(PeriodesScolaires $periodes): int
    {
        $echecs = $periodes->synchroniser(new DateTimeImmutable('now', new DateTimeZone('Europe/Paris')));
        foreach ($echecs as $e) {
            $this->warn($e);
        }
        $this->info($echecs === [] ? 'Vacances à jour.' : 'Source en échec : les admins sont prévenus.');

        return $echecs === [] ? self::SUCCESS : self::FAILURE;
    }
}

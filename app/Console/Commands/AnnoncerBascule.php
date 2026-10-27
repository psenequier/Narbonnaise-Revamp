<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reprise\AnnoncesBascule;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;

/** US-806 : annonce (au moins 7 jours avant), puis mise en service le jour J. */
final class AnnoncerBascule extends Command
{
    protected $signature = 'bascule:annoncer
        {date? : date de la bascule (AAAA-MM-JJ), pour l\'annonce}
        {--duree=une nuit : durée de la coupure annoncée}
        {--mise-en-service : le jour J, envoie les liens et la nouvelle adresse}';

    protected $description = 'Annonce la bascule aux membres, ou les prévient de la mise en service';

    public function handle(AnnoncesBascule $annonces): int
    {
        $paris = new DateTimeZone('Europe/Paris');
        if ($this->option('mise-en-service')) {
            $r = $annonces->mettreEnService();
            $this->info("Mise en service annoncée : {$r['liens']} lien(s) de mot de passe, {$r['adresse']} message(s) d'adresse.");

            return self::SUCCESS;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->argument('date'), $paris);
        if ($date === false) {
            $this->error('Date attendue au format AAAA-MM-JJ.');

            return self::FAILURE;
        }
        try {
            $n = $annonces->annoncer($date, (string) $this->option('duree'), new DateTimeImmutable('now', $paris));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info("Annonce envoyée à {$n} membre(s), en copie cachée.");

        return self::SUCCESS;
    }
}

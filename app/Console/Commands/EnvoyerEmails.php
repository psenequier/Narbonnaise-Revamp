<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Emails\BoiteEnvoi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/** Relance horaire de la boîte d'envoi (US-506), plafonnée à 150 destinataires par heure. */
final class EnvoyerEmails extends Command
{
    protected $signature = 'emails:envoyer {--max=150 : plafond de destinataires sur l\'heure glissante}';

    protected $description = 'Envoie les e-mails en attente et relance ceux en échec';

    public function handle(BoiteEnvoi $boite): int
    {
        // Verrou anti-chevauchement : une seconde relance simultanée ne fait rien (US-506-N2).
        $verrou = Cache::lock('emails:envoyer', 55 * 60);
        if (! $verrou->get()) {
            $this->info('Une relance est déjà en cours.');

            return self::SUCCESS;
        }

        try {
            $partis = $boite->envoyer(min((int) $this->option('max'), BoiteEnvoi::PLAFOND_HORAIRE));
            $this->info("{$partis} e-mail(s) envoyé(s).");
        } finally {
            $verrou->release();
        }

        return self::SUCCESS;
    }
}

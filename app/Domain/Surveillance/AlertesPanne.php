<?php

declare(strict_types=1);

namespace App\Domain\Surveillance;

use App\Domain\Emails\BoiteEnvoi;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * US-909, US-910 : prévient les admins d'une panne, sans personne d'astreinte (ADR 015).
 * Les alertes passent par la boîte d'envoi et partent aussitôt (dans la limite du plafond) :
 * elles ne doivent pas attendre un planificateur peut-être arrêté.
 * Une alerte ne fait jamais tomber le site : toute erreur pendant l'alerte est ignorée.
 */
final class AlertesPanne
{
    /** Au-delà, le planificateur est considéré comme arrêté (US-910-P2). */
    public const SILENCE_MINUTES = 120;

    public const CLE_PASSAGE = 'planificateur.dernier_passage';

    private const CLE_ALERTE_PLANIFICATEUR = 'alerte.planificateur';

    private static bool $enCours = false;

    public function __construct(private readonly BoiteEnvoi $boite) {}

    /** US-910-P1, N1 : une exception applicative ; une seule alerte par heure pour une même erreur. */
    public function exception(Throwable $e): void
    {
        $signature = hash('sha256', $e::class.'|'.$e->getFile().'|'.$e->getLine());
        $this->alerter('alerte.exception.'.$signature, 3600, 'Une erreur s\'est produite sur le site. Les membres ont vu une page d\'erreur.', [
            'Erreur : '.class_basename($e),
            'Message : '.mb_substr($e->getMessage(), 0, 300),
            'Endroit : '.self::cheminCourt($e->getFile()).', ligne '.$e->getLine(),
            'Heure : '.now()->timezone('Europe/Paris')->format('d/m/Y à H:i'),
            'Les erreurs identiques de l\'heure qui suit ne seront pas signalées.',
        ]);
    }

    /** Le planificateur passe : il note l'heure et lèvera une nouvelle alerte s'il s'arrête de nouveau. */
    public static function battement(): void
    {
        Cache::forever(self::CLE_PASSAGE, now());
        Cache::forget(self::CLE_ALERTE_PLANIFICATEUR);
    }

    public static function dernierPassage(): ?CarbonInterface
    {
        $passage = Cache::get(self::CLE_PASSAGE);

        return $passage instanceof CarbonInterface ? $passage : null;
    }

    public static function planificateurMuet(): bool
    {
        $passage = self::dernierPassage();

        // Carbon 3 : diffInMinutes est signé ; on mesure l'écart dans le bon sens.
        return $passage === null || $passage->diffInMinutes(now()) > self::SILENCE_MINUTES;
    }

    /**
     * US-910-P2 : sans passage du planificateur depuis plus de 2 heures, une alerte part ;
     * vérifié par la page de santé et, au plus tous les quarts d'heure, par les visites.
     * Une seule alerte par jour tant qu'il reste arrêté.
     */
    public function verifierPlanificateur(): void
    {
        $passage = self::dernierPassage();
        // Jamais passé (installation neuve, cache vidé) : rien à comparer, la page de santé le signale.
        if ($passage === null || ! self::planificateurMuet()) {
            return;
        }
        $this->alerter(self::CLE_ALERTE_PLANIFICATEUR, 86400, 'Les tâches automatiques du site ne tournent plus : la tâche planifiée d\'OVH semble arrêtée.', [
            'Dernier passage : '.$passage->copy()->timezone('Europe/Paris')->format('d/m/Y à H:i'),
            'Conséquences : e-mails en attente, pas de sauvegarde, pas de relecture du calendrier scolaire.',
            'À faire : espace client OVH, Hébergements, Tâches planifiées - Cron ; réactiver la tâche si elle est désactivée (OVH la coupe après 10 échecs).',
        ]);
    }

    /** US-909-N3 : une sauvegarde, son nettoyage ou son contrôle a échoué. */
    public function sauvegarde(string $quoi, string $detail): void
    {
        $this->alerter('alerte.sauvegarde.'.hash('sha256', $quoi), 3600, "Sauvegarde de la base : {$quoi}.", [
            'Détail : '.mb_substr($detail, 0, 300),
            'Heure : '.now()->timezone('Europe/Paris')->format('d/m/Y à H:i'),
            'La prochaine tentative a lieu la nuit prochaine à 3 h ; vérifiez l\'espace disque de l\'hébergement.',
        ]);
    }

    /** @param list<string> $lignes */
    private function alerter(string $cle, int $secondes, string $intro, array $lignes): void
    {
        if (self::$enCours || ! (bool) config('chamrousse.alertes_panne')) {
            return; // une erreur pendant l'alerte ne relance pas une alerte
        }
        self::$enCours = true;
        try {
            if (! Cache::add($cle, 1, $secondes)) {
                return;
            }
            $admins = BoiteEnvoi::adressesAdmins();
            if ($admins === []) {
                return;
            }
            $id = $this->boite->deposer('admin.panne', $admins, [], ['intro' => $intro, 'lignes' => $lignes]);
            $this->boite->envoyerMaintenant($id);
        } catch (Throwable) {
            // Base ou messagerie indisponible : l'erreur d'origine reste dans le journal applicatif.
        } finally {
            self::$enCours = false;
        }
    }

    private static function cheminCourt(string $fichier): string
    {
        return ltrim(str_replace(base_path(), '', $fichier), '/');
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Emails;

use App\Domain\Parametres;
use Throwable;

/**
 * Redirection de tous les e-mails vers une seule adresse, pendant les essais (répétitions,
 * tests avec la vraie base). Réglable par un admin (écran E-mails) ; sans réglage enregistré,
 * MAIL_REDIRIGER_VERS de partage/.env s'applique. « aucune » coupe la redirection.
 */
final class Redirection
{
    public const AUCUNE = 'aucune';

    public function __construct(private readonly Parametres $parametres) {}

    /** Adresse effective, ou null si les e-mails partent vers leurs vrais destinataires. */
    public function adresse(): ?string
    {
        $reglage = $this->reglage();
        if ($reglage === self::AUCUNE) {
            return null;
        }
        $adresse = $reglage !== '' ? $reglage : trim((string) config('mail.rediriger_vers', ''));

        return $adresse === '' ? null : $adresse;
    }

    /** « admin » si un admin l'a réglée sur l'écran E-mails, « serveur » sinon (partage/.env). */
    public function source(): string
    {
        return $this->reglage() !== '' ? 'admin' : 'serveur';
    }

    /** Applique la redirection à la configuration, avant la création du premier envoi. */
    public function appliquer(): void
    {
        $adresse = $this->adresse();
        config(['mail.to' => $adresse === null ? null : ['address' => $adresse, 'name' => 'Test (e-mail redirigé)']]);
    }

    private function reglage(): string
    {
        try {
            return trim($this->parametres->lire('emails.redirection'));
        } catch (Throwable) {
            return ''; // base pas encore migrée (nuit de bascule) : réglage du serveur
        }
    }
}

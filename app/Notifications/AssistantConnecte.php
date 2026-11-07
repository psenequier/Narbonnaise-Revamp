<?php

declare(strict_types=1);

namespace App\Notifications;

/** US-1001-P3 : alerte de sécurité quand un membre autorise un assistant IA. */
final class AssistantConnecte extends AlerteSecurite
{
    public function __construct(public readonly string $application, public readonly string $domaine, public readonly bool $peutReserver)
    {
        parent::__construct();
    }

    protected function sujet(): string
    {
        return 'Un assistant IA a été connecté à votre compte';
    }

    protected function evenement(): string
    {
        return 'L\'assistant « '.$this->application.' » (retour vers '.$this->domaine.') peut désormais consulter le calendrier et les statistiques'
            .($this->peutReserver ? ', et réserver, modifier ou annuler en votre nom.' : '.')
            .' Vous pouvez retirer cet accès à tout moment dans Mon profil.';
    }
}

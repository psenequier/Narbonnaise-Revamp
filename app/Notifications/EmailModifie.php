<?php

declare(strict_types=1);

namespace App\Notifications;

/** Envoyée à l'ANCIENNE adresse (US-105-P2, US-505-P2). */
final class EmailModifie extends AlerteSecurite
{
    public function __construct(public readonly string $nouvelle)
    {
        parent::__construct();
    }

    protected function sujet(): string
    {
        return 'Votre adresse de connexion a été modifiée';
    }

    protected function evenement(): string
    {
        return 'L\'adresse de connexion de votre compte est désormais '.$this->nouvelle.'.';
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

final class MotDePasseModifie extends AlerteSecurite
{
    protected function sujet(): string
    {
        return 'Votre mot de passe a été modifié';
    }

    protected function evenement(): string
    {
        return 'Le mot de passe de votre compte vient d\'être modifié et vos autres sessions ont été fermées.';
    }
}

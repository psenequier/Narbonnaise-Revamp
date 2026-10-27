<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Alerte de sécurité : envoyée quelles que soient les préférences (US-106, US-109-N1). */
final class MotDePasseModifie extends Notification
{
    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Votre mot de passe a été modifié')
            ->line('Le mot de passe de votre compte vient d\'être modifié et vos autres sessions ont été fermées.')
            ->line('Si vous n\'êtes pas à l\'origine de ce changement, prévenez immédiatement un admin.');
    }
}

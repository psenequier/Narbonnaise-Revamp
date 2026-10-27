<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Alerte de sécurité envoyée à l'ANCIENNE adresse (US-105-P2). */
final class EmailModifie extends Notification
{
    public function __construct(public readonly string $nouvelle) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Votre adresse de connexion a été modifiée')
            ->line('L\'adresse de connexion de votre compte est désormais '.$this->nouvelle.'.')
            ->line('Si vous n\'êtes pas à l\'origine de ce changement, prévenez immédiatement un admin.');
    }
}

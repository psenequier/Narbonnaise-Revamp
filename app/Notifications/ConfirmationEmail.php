<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Envoyée à la NOUVELLE adresse : tant que le lien n'est pas cliqué, rien ne change. */
final class ConfirmationEmail extends Notification
{
    public function __construct(public readonly string $lien) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirmez votre nouvelle adresse e-mail')
            ->line('Vous avez demandé à utiliser cette adresse pour vous connecter au site de l\'appartement.')
            ->action('Confirmer cette adresse', $this->lien)
            ->line('Ce lien est valable 24 heures. Sans confirmation, votre ancienne adresse reste votre identifiant.');
    }
}

<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class InvitationCompte extends Notification
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
            ->subject('Votre accès au site de l\'appartement de Chamrousse')
            ->greeting('Bonjour,')
            ->line('Un compte a été créé pour vous sur le site de réservation de l\'appartement.')
            ->action('Choisir mon mot de passe', $this->lien)
            ->line('Ce lien est valable 7 jours et ne fonctionne qu\'une fois.');
    }
}

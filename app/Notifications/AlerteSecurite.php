<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerte de sécurité (US-505) : part quelles que soient les préférences,
 * indique la date et l'heure, et un lien de signalement qui n'agit pas sur
 * le compte (simple e-mail aux admins). Jamais de mot de passe ni de lien d'action.
 */
abstract class AlerteSecurite extends Notification
{
    public readonly string $quand;

    public function __construct()
    {
        /** @var \Illuminate\Support\Carbon $maintenant */
        $maintenant = now('Europe/Paris')->locale('fr');
        $this->quand = $maintenant->translatedFormat('l j F Y à H\hi');
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    abstract protected function sujet(): string;

    abstract protected function evenement(): string;

    public function toMail(object $notifiable): MailMessage
    {
        $signalement = 'mailto:'.config('mail.from.address').'?subject='.rawurlencode('Signalement : '.$this->sujet());

        return (new MailMessage)
            ->subject($this->sujet())
            ->line($this->evenement())
            ->line('Date : '.$this->quand.' (heure de Paris).')
            ->line('Si vous n\'êtes pas à l\'origine de ce changement, signalez-le immédiatement aux admins.')
            ->action('Signaler un changement suspect', $signalement);
    }
}

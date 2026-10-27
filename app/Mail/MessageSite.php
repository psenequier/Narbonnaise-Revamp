<?php

declare(strict_types=1);

namespace App\Mail;

use App\Domain\Emails\Ics;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tous les e-mails de la boîte d'envoi. Le gabarit choisit le sujet et la vue
 * (resources/views/emails) ; les vues échappent tout ce qui vient des membres.
 */
final class MessageSite extends Mailable
{
    private const SUJETS = [
        'reservation.confirmation' => 'Votre réservation est enregistrée',
        'reservation.modifiee' => 'Votre réservation est modifiée',
        'reservation.annulee' => 'Votre réservation est annulée',
        'reservation.mouvement' => 'Du changement sur vos créneaux',
        'reservation.information' => 'Nouvelles réservations à l\'appartement',
        'reservation.action_admin' => 'Un admin a agi sur votre réservation',
        'admin.emails_non_remis' => 'E-mails non remis depuis 24 heures',
        'admin.anomalies' => 'Anomalies dans les créneaux',
    ];

    /** @param array<string, mixed> $donnees */
    public function __construct(public readonly string $gabarit, public readonly array $donnees) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: self::SUJETS[$this->gabarit] ?? 'Appartement de Chamrousse');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.'.str_replace('.', '-', $this->gabarit), with: ['d' => $this->donnees]);
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        /** @var array{uid: string, sequence: int, debut: string, fin: string, titre: string, description: string, annule?: bool}|null $ics */
        $ics = $this->donnees['ics'] ?? null;
        if ($ics === null) {
            return [];
        }

        $contenu = Ics::evenement($ics['uid'], $ics['sequence'], $ics['debut'], $ics['fin'], $ics['titre'], $ics['description'], $ics['annule'] ?? false);

        return [Attachment::fromData(fn () => $contenu, 'reservation.ics')->withMime('text/calendar; charset=utf-8')];
    }
}

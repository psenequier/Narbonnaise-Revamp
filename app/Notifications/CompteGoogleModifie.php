<?php

declare(strict_types=1);

namespace App\Notifications;

final class CompteGoogleModifie extends AlerteSecurite
{
    public function __construct(public readonly bool $lie)
    {
        parent::__construct();
    }

    protected function sujet(): string
    {
        return $this->lie ? 'Un compte Google a été lié à votre compte' : 'Votre compte Google a été délié';
    }

    protected function evenement(): string
    {
        return $this->lie
            ? 'Un compte Google permet désormais de vous connecter au site.'
            : 'Votre compte Google ne permet plus de vous connecter au site.';
    }
}

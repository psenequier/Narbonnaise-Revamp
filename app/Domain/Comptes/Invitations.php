<?php

declare(strict_types=1);

namespace App\Domain\Comptes;

use App\Models\Journal;
use App\Models\User;
use App\Notifications\InvitationCompte;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Invitation d'un membre (US-104) : lien à usage unique, valable 7 jours.
 * Un renvoi remplace le jeton, ce qui invalide l'ancien lien.
 */
final class Invitations
{
    public const VALIDITE_JOURS = 7;

    public function envoyer(User $membre): string
    {
        $jeton = Str::random(48);

        $membre->forceFill([
            'invitation_empreinte' => hash('sha256', $jeton),
            'invitation_expire_le' => now()->addDays(self::VALIDITE_JOURS),
        ])->save();

        $lien = URL::route('invitation.formulaire', ['user' => $membre->id, 'jeton' => $jeton]);
        $membre->notify(new InvitationCompte($lien));
        Journal::ecrire('invitation.envoyee', $membre);

        return $lien;
    }

    public function valide(User $membre, string $jeton): bool
    {
        return $membre->actif
            && $membre->invitation_empreinte !== null
            && $membre->invitation_expire_le !== null
            && $membre->invitation_expire_le->isFuture()
            && hash_equals($membre->invitation_empreinte, hash('sha256', $jeton));
    }

    public function activer(User $membre, string $motDePasse): void
    {
        $membre->forceFill([
            'password' => $motDePasse,
            'email_verified_at' => $membre->email_verified_at ?? now(),
            'invitation_empreinte' => null,
            'invitation_expire_le' => null,
        ])->save();

        Journal::ecrire('invitation.activee', $membre);
    }
}

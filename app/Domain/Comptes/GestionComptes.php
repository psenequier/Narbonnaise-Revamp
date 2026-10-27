<?php

declare(strict_types=1);

namespace App\Domain\Comptes;

use App\Models\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * US-601 à US-603 : création par invitation, désactivation, rôle, lien de
 * réinitialisation. L'admin ne choisit ni ne voit jamais un mot de passe ou un lien.
 */
final class GestionComptes
{
    public function __construct(private readonly Invitations $invitations) {}

    public function creer(string $prenom, string $nom, string $email, string $role): User
    {
        $membre = new User;
        // Aucun mot de passe : le membre le choisit depuis l'invitation (US-601-N3).
        $membre->forceFill([
            'prenom' => $prenom, 'nom' => $nom, 'email' => Str::lower($email),
            'role' => $role, 'password' => null, 'actif' => true,
        ])->save();
        Journal::ecrire('compte.cree', $membre, ['apres' => $membre->only(['prenom', 'nom', 'email', 'role'])]);
        $this->invitations->envoyer($membre);

        return $membre;
    }

    public function renvoyerInvitation(User $membre): void
    {
        if ($membre->password !== null) {
            throw new CompteRefuse('Ce compte est déjà activé : envoyez plutôt un lien de réinitialisation.');
        }
        if (! $membre->actif) {
            throw new CompteRefuse('Ce compte est désactivé : réactivez-le d\'abord.');
        }
        $this->invitations->envoyer($membre);
    }

    public function desactiver(User $membre): void
    {
        DB::transaction(function () use ($membre) {
            $this->exigerUnAutreAdmin($membre, 'désactiver');
            $membre->forceFill(['actif' => false, 'remember_token' => Str::random(60)])->save();
            DB::table('sessions')->where('user_id', $membre->id)->delete();
            Journal::ecrire('compte.desactive', $membre, ['avant' => ['actif' => true], 'apres' => ['actif' => false]]);
        });
    }

    public function reactiver(User $membre): void
    {
        $membre->forceFill(['actif' => true])->save();
        Journal::ecrire('compte.reactive', $membre, ['avant' => ['actif' => false], 'apres' => ['actif' => true]]);
    }

    public function changerRole(User $membre, string $role): void
    {
        if ($role === $membre->role) {
            return;
        }
        DB::transaction(function () use ($membre, $role) {
            if ($role !== 'admin') {
                $this->exigerUnAutreAdmin($membre, 'retirer le rôle d\'admin à');
            }
            $avant = $membre->role;
            $membre->forceFill(['role' => $role])->save();
            Journal::ecrire('compte.role', $membre, ['avant' => ['role' => $avant], 'apres' => ['role' => $role]]);
        });
    }

    /** US-603 : lien valable 1 heure ; ni le lien ni le jeton ne sont renvoyés ni journalisés. */
    public function envoyerLienReinitialisation(User $membre): void
    {
        if (! $membre->actif) {
            throw new CompteRefuse('Ce compte est désactivé.');
        }
        $statut = Password::broker()->sendResetLink(['email' => $membre->email]);
        if ($statut !== Password::RESET_LINK_SENT) {
            throw new CompteRefuse('Un lien vient déjà d\'être envoyé : réessayez dans une minute.');
        }
        Journal::ecrire('compte.lien_reinitialisation', $membre);
    }

    /** US-602-N1 : il reste toujours au moins un admin actif. */
    private function exigerUnAutreAdmin(User $membre, string $action): void
    {
        if ($membre->role !== 'admin' || ! $membre->actif) {
            return;
        }
        $autres = User::query()->where('role', 'admin')->where('actif', true)->whereKeyNot($membre->id)->lockForUpdate()->count();
        if ($autres === 0) {
            throw new CompteRefuse("Impossible de {$action} ce compte : c'est le dernier admin actif.");
        }
    }
}

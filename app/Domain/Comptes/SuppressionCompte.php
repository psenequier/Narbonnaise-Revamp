<?php

declare(strict_types=1);

namespace App\Domain\Comptes;

use App\Domain\Emails\BoiteEnvoi;
use App\Domain\Reservations\ServiceReservation;
use App\Models\Journal;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * US-110 : droit à l'effacement. Le membre demande, un admin confirme. Seul le prénom est
 * conservé (décision du 7 nov. 2026) : nom, e-mail, mot de passe et compte Google sont
 * effacés, y compris du détail du journal et de la boîte d'envoi. Les réservations passées
 * restent, affichées avec le prénom ; les statistiques ne changent pas. Les réservations à
 * venir sont annulées à la confirmation.
 */
final class SuppressionCompte
{
    public const MOTIF_ANNULATION = 'Compte supprimé à la demande du membre';

    public function __construct(private readonly BoiteEnvoi $boite, private readonly ServiceReservation $reservations) {}

    /** US-110-P1 : la demande prévient les admins. */
    public function demander(User $membre): void
    {
        if ($membre->suppression_demandee_le !== null) {
            return;
        }
        $membre->forceFill(['suppression_demandee_le' => now()])->save();
        Journal::ecrire('compte.suppression_demandee', $membre);
        $admins = BoiteEnvoi::adressesAdmins();
        if ($admins !== []) {
            $this->boite->deposer('admin.suppression_demandee', $admins, [], [
                'prenom' => $membre->prenom,
                'lien' => route('admin.utilisateurs.fiche', $membre),
            ]);
        }
    }

    public function supprimer(User $membre): void
    {
        if ($membre->supprime_le !== null) {
            throw new CompteRefuse('Ce compte est déjà supprimé.');
        }
        DB::transaction(function () use ($membre) {
            // US-110-N2 : il reste toujours au moins un admin actif.
            if ($membre->role === 'admin' && $membre->actif
                && User::query()->where('role', 'admin')->where('actif', true)->whereKeyNot($membre->id)->lockForUpdate()->count() === 0) {
                throw new CompteRefuse('Impossible de supprimer ce compte : c\'est le dernier admin actif.');
            }
            // US-110-N4 (décision du 7 nov. 2026) : la confirmation annule d'office les
            // réservations à venir ; les créneaux redeviennent libres.
            $maintenant = now('Europe/Paris')->toDateTimeImmutable();
            $futures = Reservation::query()->actives()->where('user_id', $membre->id)
                ->whereHas('creneaux', fn ($q) => $q->where('date', '>=', $maintenant->format('Y-m-d')))->get();
            foreach ($futures as $reservation) {
                $this->reservations->annuler($reservation, $maintenant, self::MOTIF_ANNULATION);
            }

            $email = $membre->email;
            $prenom = $membre->prenom;
            $complet = $membre->nom === '' ? [] : [$prenom.' '.$membre->nom, $membre->nom.' '.$prenom];
            $nom = $membre->nom;

            $membre->forceFill([
                'nom' => '',
                // Adresse inutilisable (domaine réservé .invalid) : plus aucun e-mail ne part (US-110-P4).
                'email' => 'compte-supprime-'.$membre->id.'@supprime.invalid',
                'email_en_attente' => null,
                'password' => null,
                'google_id' => null,
                'grr_login' => null,
                'role' => 'membre',
                'actif' => false,
                'recoit_emails_autres' => false,
                'recoit_annonces_attribution' => false,
                'invitation_empreinte' => null,
                'invitation_expire_le' => null,
                'two_factor_secret' => null,
                'two_factor_recovery_codes' => null,
                'two_factor_confirmed_at' => null,
                'remember_token' => Str::random(60),
                'supprime_le' => now(),
            ])->save();
            DB::table('sessions')->where('user_id', $membre->id)->delete();
            DB::table('password_reset_tokens')->where('email', $email)->delete();

            $this->effacerDuJournal($membre->id, $email, $nom, $prenom, $complet);
            $this->effacerDeLaBoite($email, $prenom, $complet);
            Journal::ecrire('compte.supprime', $membre);
        });
    }

    /**
     * US-110-P3 : seule modification permise du journal, avec la purge des IP. Les lignes ne
     * changent que dans leur détail : action, auteur et date restent.
     *
     * @param  list<string>  $complet
     */
    private function effacerDuJournal(int $id, string $email, string $nom, string $prenom, array $complet): void
    {
        $lignes = DB::table('journal')->where(function ($q) use ($id, $email, $complet) {
            $q->where('user_id', $id)
                ->orWhere(fn ($q) => $q->where('objet_type', 'User')->where('objet_id', $id))
                ->orWhere('details', 'like', '%'.self::like($email).'%');
            foreach ($complet as $c) {
                $q->orWhere('details', 'like', '%'.self::like($c).'%');
            }
        })->whereNotNull('details')->get(['id', 'user_id', 'objet_type', 'objet_id', 'details']);

        foreach ($lignes as $l) {
            $details = json_decode((string) $l->details, true);
            if (! is_array($details)) {
                continue;
            }
            // Les champs nom et e-mail ne sont retirés que des entrées qui portent sur son compte.
            $sienne = $l->objet_type === 'User' && (int) $l->objet_id === $id;
            $nettoye = self::nettoyer($details, $email, $sienne ? $nom : null, $prenom, $complet);
            if ($nettoye !== $details) {
                DB::table('journal')->where('id', $l->id)->update(['details' => json_encode($nettoye, JSON_THROW_ON_ERROR)]);
            }
        }
    }

    /**
     * US-110-P4 : son adresse disparaît de la boîte d'envoi ; un e-mail qui n'était adressé
     * qu'à lui est retiré.
     *
     * @param  list<string>  $complet
     */
    private function effacerDeLaBoite(string $email, string $prenom, array $complet): void
    {
        $lignes = DB::table('emails_sortants')->where('destinataires', 'like', '%'.self::like($email).'%')
            ->orWhere('donnees', 'like', '%'.self::like($email).'%')->get(['id', 'destinataires', 'donnees']);
        foreach ($lignes as $l) {
            /** @var array{a?: list<string>, cci?: list<string>} $dest */
            $dest = json_decode((string) $l->destinataires, true) ?: [];
            $a = array_values(array_filter($dest['a'] ?? [], fn ($x) => mb_strtolower($x) !== mb_strtolower($email)));
            $cci = array_values(array_filter($dest['cci'] ?? [], fn ($x) => mb_strtolower($x) !== mb_strtolower($email)));
            if ($a === [] && $cci === []) {
                DB::table('emails_sortants')->where('id', $l->id)->delete();

                continue;
            }
            $donnees = json_decode((string) $l->donnees, true);
            DB::table('emails_sortants')->where('id', $l->id)->update([
                'destinataires' => json_encode(['a' => $a, 'cci' => $cci], JSON_THROW_ON_ERROR),
                'nombre_destinataires' => count($a) + count($cci),
                'donnees' => json_encode(is_array($donnees) ? self::nettoyer($donnees, $email, null, $prenom, $complet) : [], JSON_THROW_ON_ERROR),
            ]);
        }
    }

    /**
     * @param  array<mixed>  $valeur
     * @param  list<string>  $complet
     * @return array<mixed>
     */
    private static function nettoyer(array $valeur, string $email, ?string $nom, string $prenom, array $complet): array
    {
        foreach ($valeur as $cle => $v) {
            if (is_array($v)) {
                $valeur[$cle] = self::nettoyer($v, $email, $nom, $prenom, $complet);
            } elseif (is_string($v)) {
                if ($nom !== null && in_array($cle, ['nom', 'email'], true)) {
                    unset($valeur[$cle]);

                    continue;
                }
                $v = str_ireplace($email, '[adresse effacée]', $v);
                foreach ($complet as $c) {
                    $v = str_replace($c, $prenom, $v);
                }
                $valeur[$cle] = $v;
            }
        }

        return $valeur;
    }

    private static function like(string $texte): string
    {
        return addcslashes($texte, '%_\\');
    }
}

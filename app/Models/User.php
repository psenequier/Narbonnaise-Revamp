<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $prenom
 * @property string $nom
 * @property string $email
 * @property string $role
 * @property bool $actif
 * @property string|null $password
 * @property string|null $google_id
 * @property string|null $email_en_attente
 * @property string|null $invitation_empreinte
 * @property \Illuminate\Support\Carbon|null $invitation_expire_le
 * @property \Illuminate\Support\Carbon|null $two_factor_confirmed_at
 * @property \Illuminate\Support\Carbon|null $email_verified_at
 * @property bool $recoit_emails_autres
 * @property bool $a_reinitialiser
 * @property string|null $grr_login
 * @property bool $recoit_annonces_attribution
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /** Champs modifiables par le membre lui-même. Le rôle n'en fait pas partie (A01). */
    protected $fillable = ['prenom', 'nom', 'email', 'password', 'recoit_emails_autres'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'invitation_empreinte'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'password' => 'hashed',
            'actif' => 'boolean',
            'recoit_emails_autres' => 'boolean',
            'recoit_annonces_attribution' => 'boolean',
            'a_reinitialiser' => 'boolean',
            'invitation_expire_le' => 'datetime',
        ];
    }

    /** Invité (invitation non activée), Actif ou Désactivé. */
    public function statut(): string
    {
        return match (true) {
            ! $this->actif => 'Désactivé',
            $this->a_reinitialiser => 'À réinitialiser',
            $this->password === null => 'Invité',
            default => 'Actif',
        };
    }

    public function estAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Destinataires des e-mails « réservations des autres » (US-503, US-109-P2).
     *
     * @param  \Illuminate\Database\Eloquent\Builder<User>  $requete
     */
    public function scopeRecoitReservationsDesAutres(\Illuminate\Database\Eloquent\Builder $requete): void
    {
        $requete->where('actif', true)->whereNotNull('password')->where('recoit_emails_autres', true);
    }

    /** @return HasMany<Reservation, $this> */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Autorisation donnée par un membre à un assistant IA (US-1001, US-1002).
 *
 * @property int $id
 * @property int $client_id
 * @property int $user_id
 * @property list<string> $droits
 * @property string $jeton_empreinte
 * @property \Illuminate\Support\Carbon $jeton_expire_le
 * @property string $renouvellement_empreinte
 * @property string|null $ancien_renouvellement_empreinte
 * @property \Illuminate\Support\Carbon $renouvellement_expire_le
 * @property \Illuminate\Support\Carbon $autorise_le
 * @property \Illuminate\Support\Carbon $expire_au_plus_tard_le
 * @property \Illuminate\Support\Carbon|null $utilise_le
 * @property \Illuminate\Support\Carbon|null $revoque_le
 * @property-read ClientAssistant|null $client
 * @property-read User|null $user
 */
class AccesAssistant extends Model
{
    public const LECTURE = 'lecture';

    public const RESERVATION = 'reservation';

    protected $table = 'assistants_acces';

    protected $guarded = ['id'];

    protected $hidden = ['jeton_empreinte', 'renouvellement_empreinte', 'ancien_renouvellement_empreinte'];

    protected function casts(): array
    {
        return [
            'droits' => 'array',
            'jeton_expire_le' => 'datetime',
            'renouvellement_expire_le' => 'datetime',
            'autorise_le' => 'datetime',
            'expire_au_plus_tard_le' => 'datetime',
            'utilise_le' => 'datetime',
            'revoque_le' => 'datetime',
        ];
    }

    /** @return BelongsTo<ClientAssistant, $this> */
    public function client(): BelongsTo
    {
        return $this->belongsTo(ClientAssistant::class, 'client_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Accès encore utilisables : ni retirés ni expirés.
     *
     * @param  Builder<AccesAssistant>  $requete
     */
    public function scopeEnCours(Builder $requete): void
    {
        $requete->whereNull('revoque_le')->where('renouvellement_expire_le', '>', now());
    }

    public function peut(string $droit): bool
    {
        return in_array($droit, $this->droits, true);
    }
}

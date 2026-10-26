<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property int $user_id
 * @property string $mode
 * @property string|null $commentaire
 * @property \Illuminate\Support\Carbon|null $annulee_le
 */
class Reservation extends Model
{
    use HasFactory;

    protected $fillable = ['mode', 'commentaire', 'nombre_personnes'];

    protected function casts(): array
    {
        return ['annulee_le' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Creneau, $this> */
    public function creneaux(): BelongsToMany
    {
        return $this->belongsToMany(Creneau::class, 'reservation_creneau')->orderBy('date')->orderBy('type');
    }

    /** @param Builder<Reservation> $query */
    public function scopeActives(Builder $query): void
    {
        $query->whereNull('annulee_le');
    }
}

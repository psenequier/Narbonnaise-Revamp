<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * @property int $id
 * @property \Illuminate\Support\Carbon $date
 * @property string $type
 * @property int|null $periode_id
 */
class Creneau extends Model
{
    protected $table = 'creneaux';

    protected $fillable = ['date', 'type', 'periode_id'];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** @return BelongsTo<PeriodeVacances, $this> */
    public function periode(): BelongsTo
    {
        return $this->belongsTo(PeriodeVacances::class, 'periode_id');
    }

    /** @return HasOne<Attribution, $this> */
    public function attribution(): HasOne
    {
        return $this->hasOne(Attribution::class);
    }

    /** @return BelongsToMany<Reservation, $this> */
    public function reservations(): BelongsToMany
    {
        return $this->belongsToMany(Reservation::class, 'reservation_creneau');
    }
}

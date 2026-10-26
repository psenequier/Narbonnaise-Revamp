<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AnneeScolaire extends Model
{
    protected $table = 'annees_scolaires';

    protected $fillable = ['libelle', 'debut', 'fin'];

    protected function casts(): array
    {
        return ['debut' => 'date', 'fin' => 'date', 'ouverte' => 'boolean', 'ouverte_le' => 'datetime'];
    }

    /** @return HasMany<PeriodeVacances, $this> */
    public function periodes(): HasMany
    {
        return $this->hasMany(PeriodeVacances::class);
    }

    /** Ouvrable seulement si Noël et février sont connus. */
    public function peutOuvrir(): bool
    {
        return $this->periodes()->whereIn('nom', ['noel', 'hiver'])->count() === 2;
    }
}

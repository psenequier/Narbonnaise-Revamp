<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodeVacances extends Model
{
    protected $table = 'periodes_vacances';

    protected $fillable = ['annee_scolaire_id', 'nom', 'premiere_nuit', 'dernier_jour', 'source_empreinte'];

    protected function casts(): array
    {
        return ['premiere_nuit' => 'date', 'dernier_jour' => 'date'];
    }

    /** @return BelongsTo<AnneeScolaire, $this> */
    public function annee(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class, 'annee_scolaire_id');
    }
}

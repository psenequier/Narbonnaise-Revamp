<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $annee_scolaire_id
 * @property string $nom
 * @property \Illuminate\Support\Carbon $premiere_nuit
 * @property \Illuminate\Support\Carbon $dernier_jour
 * @property bool $manuelle
 * @property string $source_empreinte
 * @property-read AnneeScolaire $annee
 */
class PeriodeVacances extends Model
{
    protected $table = 'periodes_vacances';

    protected $fillable = ['annee_scolaire_id', 'nom', 'premiere_nuit', 'dernier_jour', 'source_empreinte'];

    protected function casts(): array
    {
        return ['premiere_nuit' => 'date', 'dernier_jour' => 'date', 'manuelle' => 'boolean'];
    }

    public function libelle(): string
    {
        return $this->nom === 'noel' ? 'Noël' : 'Février';
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<Plage, $this> */
    public function plages(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Plage::class, 'periode_id')->orderBy('debut')->orderBy('debut_type');
    }

    /** @return BelongsTo<AnneeScolaire, $this> */
    public function annee(): BelongsTo
    {
        return $this->belongsTo(AnneeScolaire::class, 'annee_scolaire_id');
    }
}

<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Calendrier\Creneau as CreneauDemande;
use App\Domain\Calendrier\TypeCreneau;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $periode_id
 * @property int $lignee_id
 * @property \Illuminate\Support\Carbon $debut
 * @property string $debut_type
 * @property \Illuminate\Support\Carbon $fin
 * @property string $fin_type
 * @property int $saisie_par
 * @property-read Lignee $lignee
 * @property-read PeriodeVacances $periode
 */
class Plage extends Model
{
    protected $fillable = ['periode_id', 'lignee_id', 'debut', 'debut_type', 'fin', 'fin_type'];

    protected function casts(): array
    {
        return ['debut' => 'date', 'fin' => 'date'];
    }

    /** @return BelongsTo<Lignee, $this> */
    public function lignee(): BelongsTo
    {
        return $this->belongsTo(Lignee::class);
    }

    /** @return BelongsTo<PeriodeVacances, $this> */
    public function periode(): BelongsTo
    {
        return $this->belongsTo(PeriodeVacances::class, 'periode_id');
    }

    public function premier(): CreneauDemande
    {
        return new CreneauDemande(new DateTimeImmutable($this->debut->format('Y-m-d')), TypeCreneau::from($this->debut_type));
    }

    public function dernier(): CreneauDemande
    {
        return new CreneauDemande(new DateTimeImmutable($this->fin->format('Y-m-d')), TypeCreneau::from($this->fin_type));
    }
}

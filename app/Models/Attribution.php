<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $creneau_id
 * @property int $lignee_id
 * @property ?int $plage_id
 * @property-read ?Lignee $lignee
 * @property-read ?Plage $plage
 */
class Attribution extends Model
{
    protected $fillable = ['creneau_id', 'lignee_id', 'plage_id', 'saisie_par'];

    /** @return BelongsTo<Lignee, $this> */
    public function lignee(): BelongsTo
    {
        return $this->belongsTo(Lignee::class);
    }

    /** @return BelongsTo<Plage, $this> */
    public function plage(): BelongsTo
    {
        return $this->belongsTo(Plage::class);
    }

    /** @return BelongsTo<Creneau, $this> */
    public function creneau(): BelongsTo
    {
        return $this->belongsTo(Creneau::class);
    }
}

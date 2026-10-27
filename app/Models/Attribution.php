<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Attribution extends Model
{
    protected $fillable = ['creneau_id', 'lignee_id', 'plage_id', 'saisie_par'];

    /** @return BelongsTo<Lignee, $this> */
    public function lignee(): BelongsTo
    {
        return $this->belongsTo(Lignee::class);
    }

    /** @return BelongsTo<Creneau, $this> */
    public function creneau(): BelongsTo
    {
        return $this->belongsTo(Creneau::class);
    }
}

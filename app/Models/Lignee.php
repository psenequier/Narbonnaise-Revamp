<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pas de rattachement des membres : une lignée ne sert qu'aux attributions.
 *
 * @property int $id
 * @property string $nom
 * @property string $couleur
 */
class Lignee extends Model
{
    protected $fillable = ['nom', 'couleur'];
}

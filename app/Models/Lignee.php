<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Pas de rattachement des membres : une lignée ne sert qu'aux attributions.
 * Nom et couleur se modifient dans Admin → Lignées ; LIGNEES (.env) ne sert qu'au premier remplissage.
 *
 * @property int $id
 * @property string $nom
 * @property string $couleur
 */
class Lignee extends Model
{
    public const COULEUR = '/^#[0-9A-Fa-f]{6}$/';

    protected $fillable = ['nom', 'couleur'];

    /**
     * Crée les lignées décrites par « Nom:#couleur,Nom:#couleur,… » si la table est vide.
     * Ne touche jamais des lignées existantes (renommées par un admin, par exemple).
     *
     * @return int nombre de lignées créées
     */
    public static function remplirSiVide(string $config): int
    {
        if (self::query()->exists()) {
            return 0;
        }
        $creees = 0;
        foreach (array_filter(array_map('trim', explode(',', $config))) as $entree) {
            [$nom, $couleur] = array_map('trim', explode(':', $entree, 2)) + [1 => '#5B6673'];
            if ($nom === '' || preg_match(self::COULEUR, $couleur) !== 1) {
                continue;
            }
            self::query()->firstOrCreate(['nom' => $nom], ['couleur' => $couleur]);
            $creees++;
        }

        return $creees;
    }
}

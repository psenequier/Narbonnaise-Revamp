<?php

namespace Database\Seeders;

use App\Models\Lignee;
use Illuminate\Database\Seeder;

/**
 * Données de référence. Le dépôt est public : les noms des lignées ne sont
 * pas dans le code. En production, ils viennent de LIGNEES dans partage/.env
 * (« Nom:#couleur,Nom:#couleur,… ») ; ailleurs, des noms fictifs. Seulement si
 * aucune lignée n'existe : ensuite, nom et couleur se modifient dans l'admin.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        Lignee::remplirSiVide((string) (config('chamrousse.lignees') ?: 'Lignée A:#8BBF7E,Lignée B:#F2B5C4,Lignée C:#F2D06B'));
    }
}

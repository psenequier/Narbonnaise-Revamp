<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Données de référence. Le dépôt est public : les noms des lignées ne sont
 * pas dans le code. En production, ils viennent de LIGNEES dans partage/.env
 * (« Nom:#couleur,Nom:#couleur,… ») ; ailleurs, des noms fictifs.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $config = (string) (config('chamrousse.lignees') ?: 'Lignée A:#2F6FB0,Lignée B:#B5651D,Lignée C:#6B4FA0');

        foreach (array_filter(explode(',', $config)) as $entree) {
            [$nom, $couleur] = array_map('trim', explode(':', $entree, 2)) + [1 => '#5B6673'];
            DB::table('lignees')->updateOrInsert(['nom' => $nom], ['couleur' => $couleur, 'updated_at' => now(), 'created_at' => now()]);
        }
    }
}

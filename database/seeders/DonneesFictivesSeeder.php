<?php

namespace Database\Seeders;

use App\Domain\Calendrier\CalculPeriodes;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Site éphémère de la CI et poste de développement : uniquement des données
 * fictives (adresses en example.test). Refuse de tourner en production.
 */
class DonneesFictivesSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('Données fictives interdites en production.');
        }

        $this->call(DatabaseSeeder::class);

        $admin = User::factory()->admin()->create(['prenom' => 'Ada', 'nom' => 'Admin', 'email' => 'admin@example.test']);
        User::factory()->create(['prenom' => 'Marc', 'nom' => 'Membre', 'email' => 'membre@example.test']);
        User::factory()->count(8)->create();

        // Année 2026-2027 ouverte, avec Noël et février calculés comme en production.
        $annee = DB::table('annees_scolaires')->insertGetId([
            'libelle' => '2026-2027', 'debut' => '2026-09-01', 'fin' => '2027-08-31',
            'ouverte' => true, 'ouverte_le' => now(), 'ouverte_par' => $admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $calcul = new CalculPeriodes;
        $periodes = [
            'noel' => $calcul->periode('noel', '2026-12-19', '2027-01-04'),
            'hiver' => $calcul->periode('hiver', '2027-02-13', '2027-03-01'),
        ];
        $ids = [];
        foreach ($periodes as $nom => $p) {
            $ids[$nom] = DB::table('periodes_vacances')->insertGetId([
                'annee_scolaire_id' => $annee, 'nom' => $nom,
                'premiere_nuit' => $p->premiereNuit->format('Y-m-d'), 'dernier_jour' => $p->dernierJour->format('Y-m-d'),
                'source_empreinte' => 'fictif', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $lignes = [];
        for ($d = new \DateTimeImmutable('2026-09-01'); $d <= new \DateTimeImmutable('2027-08-31'); $d = $d->modify('+1 day')) {
            foreach ([\App\Domain\Calendrier\TypeCreneau::Jour, \App\Domain\Calendrier\TypeCreneau::Nuit] as $type) {
                $creneau = new \App\Domain\Calendrier\Creneau($d, $type);
                $periode = null;
                foreach ($periodes as $nom => $p) {
                    if ($p->contient($creneau)) {
                        $periode = $ids[$nom];
                    }
                }
                $lignes[] = ['date' => $d->format('Y-m-d'), 'type' => $type->value, 'periode_id' => $periode, 'created_at' => now(), 'updated_at' => now()];
            }
        }
        foreach (array_chunk($lignes, 200) as $lot) {
            DB::table('creneaux')->insert($lot);
        }
    }
}

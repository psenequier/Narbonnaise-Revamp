<?php

declare(strict_types=1);

namespace App\Domain\Reprise;

use App\Models\Journal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * US-805-N2 : les tables GRR ne se suppriment qu'avec un dernier contrôle de la
 * reprise validé ET un export chiffré, présent et intact. Rien d'autre ne les supprime.
 */
final class SuppressionGrr
{
    public function __construct(
        private readonly BaseGrr $grr,
        private readonly ExportBascule $export,
    ) {}

    /** @return list<string> raisons du refus ; vide = suppression permise */
    public function refus(): array
    {
        $refus = [];
        $controle = DB::table('reprise_rapports')->where('type', 'controle')->orderByDesc('id')->first();
        if ($controle === null || ! (bool) $controle->valide) {
            $refus[] = 'Le dernier contrôle de la reprise n\'est pas validé.';
        }
        if ($this->export->dernier(ExportBascule::GRR) === null) {
            $refus[] = 'Aucun export chiffré et intact des tables GRR.';
        }

        return $refus;
    }

    /** @return list<string> tables GRR présentes */
    public function tables(): array
    {
        $tables = [];
        foreach (['utilisateurs', 'entry', 'type_area', 'area', 'room', 'j_mailuser_room'] as $nom) {
            $table = $this->grr->table($nom);
            try {
                DB::table($table)->limit(1)->get();
                $tables[] = $table;
            } catch (QueryException) {
                // déjà absente
            }
        }

        return $tables;
    }

    /** @return list<string> tables supprimées */
    public function supprimer(): array
    {
        $refus = $this->refus();
        if ($refus !== []) {
            throw new RuntimeException(implode(' ', $refus));
        }

        $permanentes = array_map(fn ($l) => (string) array_values((array) $l)[0], DB::select('SHOW TABLES'));
        $supprimees = [];
        foreach ($this->tables() as $table) {
            // Une table temporaire n'apparaît pas dans SHOW TABLES : DROP TEMPORARY évite un commit implicite.
            // Le nom, validé par nomSql(), vient du préfixe GRR de la configuration, jamais d'une saisie.
            $requete = (in_array($table, $permanentes, true) ? 'DROP TABLE ' : 'DROP TEMPORARY TABLE ').ExportBascule::nomSql($table);
            DB::statement($requete);
            $supprimees[] = $table;
        }
        Journal::ecrire('grr.tables_supprimees', null, ['tables' => count($supprimees)]);

        return $supprimees;
    }
}

<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Reprise\ControleReprise;
use App\Domain\Reprise\ImportGrr;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** US-801, US-802 : reprise de GRR, relançable sans doublon, suivie du contrôle (US-803). */
final class ImporterGrr extends Command
{
    protected $signature = 'grr:importer {--controle-seul : ne fait que le rapport de contrôle}';

    protected $description = 'Reprend les comptes et les réservations de GRR (tables grr_*), puis contrôle la reprise';

    public function handle(ImportGrr $import, ControleReprise $controle): int
    {
        foreach (['grr_utilisateurs', 'grr_entry', 'grr_type_area'] as $table) {
            try {
                DB::table($table)->limit(1)->get();
            } catch (QueryException) {
                $this->error("Table {$table} introuvable : la base GRR doit être dans la même base de données.");

                return self::FAILURE;
            }
        }

        if (! $this->option('controle-seul')) {
            $r = $import->importer();
            $n = fn (string $cle): int => is_int($r[$cle] ?? null) ? $r[$cle] : 0;
            $this->info('Comptes : '.$n('comptes_repris').' repris sur '.$n('comptes_grr').'. Réservations : '.$n('reservations')
                .' sur '.$n('entrees_grr').' entrées, '.$n('propositions').' attribution(s) à valider.');
            foreach (['a_reinitialiser' => 'À réinitialiser (ancien mot de passe MD5)', 'doublons_email' => 'Doublons d\'e-mail', 'comptes_rejetes' => 'Comptes rejetés', 'rejets' => 'Entrées rejetées', 'chevauchements' => 'Chevauchements historiques'] as $cle => $titre) {
                $lignes = is_array($r[$cle] ?? null) ? $r[$cle] : [];
                if ($lignes !== []) {
                    $this->warn("{$titre} : ".count($lignes));
                    foreach ($lignes as $l) {
                        $this->line("  - {$l}");
                    }
                }
            }
        }

        $c = $controle->rapport();
        $this->table(['Année', 'Réservations GRR', 'Réservations site', 'Nuits GRR', 'Nuits site', 'Écart'],
            collect($c['annees'])->map(fn ($a, $annee) => [$annee, $a['grr_reservations'], $a['site_reservations'], $a['grr_nuits'], $a['site_nuits'], $a['ecart'] ? 'OUI' : ''])->values()->all());
        $this->line("Comptes GRR : {$c['comptes']['grr']} = repris {$c['comptes']['repris']} + anomalies {$c['comptes']['anomalies']}".($c['comptes']['ecart'] ? ' : ÉCART' : ''));
        $c['valide'] ? $this->info('Reprise validée.') : $this->error('Reprise NON VALIDÉE : voir les écarts.');

        return $c['valide'] ? self::SUCCESS : self::FAILURE;
    }
}

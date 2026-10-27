<?php

declare(strict_types=1);

namespace App\Domain\Reprise;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * US-805-P1, étapes 1 et 2 : avant toute migration, la nuit de la bascule,
 * sauvegarde complète de la base (SQL) et export des tables GRR (SQL et CSV).
 * Chaque archive est chiffrée en AES-256, relue et vérifiée, puis décrite par
 * un fichier .json voisin (empreinte, lignes par table). Les archives restent
 * hors du dossier publié ; un admin les télécharge depuis l'écran Reprise pour
 * les ranger hors d'OVH. GRR n'est jamais modifié.
 */
final class ExportBascule
{
    public const SAUVEGARDE = 'sauvegarde';

    public const GRR = 'grr';

    /** Tables lues par la reprise : toujours exportées, même si SHOW TABLES ne les liste pas. */
    private const TABLES_GRR = ['utilisateurs', 'entry', 'type_area', 'area', 'room', 'j_mailuser_room'];

    public function __construct(private readonly BaseGrr $grr) {}

    public static function dossier(): string
    {
        $dossier = config('chamrousse.dossier_exports');

        return is_string($dossier) && $dossier !== '' ? $dossier : storage_path('app/exports');
    }

    /**
     * @return array{type: string, fichier: string, empreinte: string, tables: array<string, int>, cree_le: string}
     */
    public function sauvegarder(): array
    {
        $tables = array_values(array_unique([...$this->tablesDeLaBase(), ...$this->tablesGrr()]));

        return $this->archiver(self::SAUVEGARDE, $tables, csv: false);
    }

    /**
     * @return array{type: string, fichier: string, empreinte: string, tables: array<string, int>, cree_le: string}
     */
    public function exporterGrr(): array
    {
        $tables = $this->tablesGrr();
        if ($tables === []) {
            throw new RuntimeException('Aucune table GRR trouvée : vérifiez GRR_PREFIXE.');
        }

        return $this->archiver(self::GRR, $tables, csv: true);
    }

    /**
     * Dernière archive du type, seulement si son fichier est présent et intact.
     *
     * @return array{type: string, fichier: string, empreinte: string, tables: array<string, int>, cree_le: string}|null
     */
    public function dernier(string $type): ?array
    {
        foreach ($this->archives() as $archive) {
            if ($archive['type'] === $type && $archive['intacte']) {
                unset($archive['intacte']);

                return $archive;
            }
        }

        return null;
    }

    /**
     * Archives présentes, de la plus récente à la plus ancienne.
     *
     * @return list<array{type: string, fichier: string, empreinte: string, tables: array<string, int>, cree_le: string, intacte: bool}>
     */
    public function archives(): array
    {
        $descriptions = glob(self::dossier().'/*.json') ?: [];
        rsort($descriptions);
        $archives = [];
        foreach ($descriptions as $chemin) {
            $meta = json_decode((string) file_get_contents($chemin), true);
            if (! is_array($meta) || ! is_string($meta['fichier'] ?? null) || ! is_string($meta['empreinte'] ?? null)
                || ! is_string($meta['type'] ?? null) || ! is_array($meta['tables'] ?? null) || ! is_string($meta['cree_le'] ?? null)) {
                continue;
            }
            $zip = self::dossier().'/'.basename($meta['fichier']);
            $lignes = [];
            foreach ($meta['tables'] as $table => $n) {
                $lignes[(string) $table] = is_numeric($n) ? (int) $n : 0;
            }
            $archives[] = [
                'type' => $meta['type'],
                'fichier' => basename($meta['fichier']),
                'empreinte' => $meta['empreinte'],
                'tables' => $lignes,
                'cree_le' => $meta['cree_le'],
                'intacte' => is_file($zip) && hash_file('sha256', $zip) === $meta['empreinte'],
            ];
        }

        return $archives;
    }

    /** Nom de fichier acceptable pour le téléchargement (pas de chemin, pas d'autre extension). */
    public static function nomValide(string $fichier): bool
    {
        return preg_match('/^(sauvegarde|grr)-\d{8}-\d{6}-[a-f0-9]{6}\.zip$/', $fichier) === 1;
    }

    /**
     * @param  list<string>  $tables
     * @return array{type: string, fichier: string, empreinte: string, tables: array<string, int>, cree_le: string}
     */
    private function archiver(string $type, array $tables, bool $csv): array
    {
        $motDePasse = config('chamrousse.export_mot_de_passe');
        if (! is_string($motDePasse) || strlen($motDePasse) < 12) {
            throw new RuntimeException('BACKUP_ARCHIVE_PASSWORD absent ou trop court (12 caractères au moins) : aucune archive en clair.');
        }

        $dossier = self::dossier();
        if (! is_dir($dossier) && ! mkdir($dossier, 0700, true) && ! is_dir($dossier)) {
            throw new RuntimeException('Dossier des archives impossible à créer.');
        }
        $temporaire = $dossier.'/tmp-'.bin2hex(random_bytes(6));
        mkdir($temporaire, 0700);

        try {
            $entrees = [];
            $lignes = [];
            foreach ($tables as $table) {
                $sql = $temporaire.'/'.$table.'.sql';
                $lignes[$table] = $this->ecrireSql($table, $sql);
                $entrees[$table.'.sql'] = $sql;
                if ($csv) {
                    $fichierCsv = $temporaire.'/'.$table.'.csv';
                    $this->ecrireCsv($table, $fichierCsv);
                    $entrees[$table.'.csv'] = $fichierCsv;
                }
            }

            $nom = $type.'-'.now('Europe/Paris')->format('Ymd-His').'-'.bin2hex(random_bytes(3)).'.zip';
            $zip = $dossier.'/'.$nom;
            $this->zipper($zip, $entrees, $motDePasse);
            $this->verifier($zip, $entrees, $motDePasse);
            chmod($zip, 0600);

            $meta = [
                'type' => $type,
                'fichier' => $nom,
                'empreinte' => (string) hash_file('sha256', $zip),
                'tables' => $lignes,
                'cree_le' => now()->toIso8601String(),
            ];
            file_put_contents(substr($zip, 0, -4).'.json', json_encode($meta, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

            return $meta;
        } finally {
            foreach (glob($temporaire.'/*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($temporaire);
        }
    }

    /** @param array<string, string> $entrees */
    private function zipper(string $chemin, array $entrees, string $motDePasse): void
    {
        $zip = new ZipArchive;
        if ($zip->open($chemin, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Archive impossible à créer.');
        }
        foreach ($entrees as $nom => $fichier) {
            if (! $zip->addFile($fichier, $nom) || ! $zip->setEncryptionName($nom, ZipArchive::EM_AES_256, $motDePasse)) {
                $zip->close();
                @unlink($chemin);
                throw new RuntimeException('Chiffrement de l\'archive impossible.');
            }
        }
        if (! $zip->close()) {
            @unlink($chemin);
            throw new RuntimeException('Écriture de l\'archive impossible.');
        }
    }

    /**
     * Relit l'archive avec le mot de passe : chaque fichier doit être chiffré
     * en AES-256 et identique à celui écrit.
     *
     * @param  array<string, string>  $entrees
     */
    private function verifier(string $chemin, array $entrees, string $motDePasse): void
    {
        $zip = new ZipArchive;
        if ($zip->open($chemin, ZipArchive::RDONLY) !== true || $zip->numFiles !== count($entrees)) {
            @unlink($chemin);
            throw new RuntimeException('Archive illisible après écriture.');
        }
        $zip->setPassword($motDePasse);
        foreach ($entrees as $nom => $fichier) {
            $stat = $zip->statName($nom);
            $contenu = $zip->getFromName($nom);
            if ($stat === false || $stat['encryption_method'] !== ZipArchive::EM_AES_256
                || $contenu === false || hash('sha256', $contenu) !== hash_file('sha256', $fichier)) {
                $zip->close();
                @unlink($chemin);
                throw new RuntimeException("Vérification de l'archive en échec ({$nom}).");
            }
        }
        $zip->close();
    }

    private function ecrireSql(string $table, string $chemin): int
    {
        $f = fopen($chemin, 'wb');
        if ($f === false) {
            throw new RuntimeException('Écriture temporaire impossible.');
        }
        $nom = self::nomSql($table);
        // Un nom de table ne se passe pas en paramètre lié : il est validé par nomSql() (lettres,
        // chiffres, _), et vient de SHOW TABLES ou du préfixe GRR, jamais d'une saisie.
        $requete = 'SHOW CREATE TABLE '.$nom;
        $creation = (array) DB::selectOne($requete);
        $ddl = (string) ($creation['Create Table'] ?? array_values($creation)[1] ?? '');
        fwrite($f, "DROP TABLE IF EXISTS {$nom};\n".preg_replace('/^CREATE TEMPORARY TABLE/', 'CREATE TABLE', $ddl).";\n");

        $pdo = DB::connection()->getPdo();
        $n = 0;
        foreach (DB::table($table)->cursor() as $ligne) {
            $valeurs = (array) $ligne;
            $colonnes = implode(',', array_map(fn ($c) => self::nomSql((string) $c), array_keys($valeurs)));
            $donnees = implode(',', array_map(fn ($v) => $v === null ? 'NULL' : $pdo->quote(is_scalar($v) ? (string) $v : (string) json_encode($v)), $valeurs));
            fwrite($f, "INSERT INTO {$nom} ({$colonnes}) VALUES ({$donnees});\n");
            $n++;
        }
        fclose($f);

        return $n;
    }

    private function ecrireCsv(string $table, string $chemin): void
    {
        $f = fopen($chemin, 'wb');
        if ($f === false) {
            throw new RuntimeException('Écriture temporaire impossible.');
        }
        $entete = false;
        foreach (DB::table($table)->cursor() as $ligne) {
            $valeurs = (array) $ligne;
            if (! $entete) {
                fputcsv($f, array_map('strval', array_keys($valeurs)), ';', '"', '');
                $entete = true;
            }
            fputcsv($f, array_map(fn ($v) => $v === null ? '' : (is_scalar($v) ? (string) $v : (string) json_encode($v)), array_values($valeurs)), ';', '"', '');
        }
        if (! $entete) {
            $colonnes = \Illuminate\Support\Facades\Schema::getColumnListing($table);
            fputcsv($f, $colonnes, ';', '"', '');
        }
        fclose($f);
    }

    /** @return list<string> */
    private function tablesDeLaBase(): array
    {
        $tables = [];
        foreach (DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $ligne) {
            $tables[] = (string) array_values((array) $ligne)[0];
        }

        return $tables;
    }

    /** @return list<string> */
    private function tablesGrr(): array
    {
        $prefixe = $this->grr->table('');
        $tables = [];
        // SHOW TABLES LIKE ? ne se prépare pas : information_schema accepte un paramètre lié.
        $lignes = DB::select(
            "SELECT table_name AS nom FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE' AND table_name LIKE ?",
            [addcslashes($prefixe, '\\%_').'%'],
        );
        foreach ($lignes as $ligne) {
            $tables[] = (string) array_values((array) $ligne)[0];
        }
        foreach (self::TABLES_GRR as $nom) {
            $table = $this->grr->table($nom);
            if (! in_array($table, $tables, true) && $this->existe($table)) {
                $tables[] = $table;
            }
        }
        sort($tables);

        return $tables;
    }

    private function existe(string $table): bool
    {
        try {
            DB::table($table)->limit(1)->get();

            return true;
        } catch (QueryException) {
            return false;
        }
    }

    /** Identifiant SQL validé : lettres, chiffres et _ seulement, sinon refus. */
    public static function nomSql(string $nom): string
    {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $nom) !== 1) {
            throw new RuntimeException('Nom de table refusé.');
        }

        return '`'.$nom.'`';
    }
}

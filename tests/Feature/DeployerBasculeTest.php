<?php

use Illuminate\Support\Facades\Process;

/*
 * Le script de déploiement, joué avec un faux lftp et un faux curl : on lit ce
 * qu'il aurait fait sur le serveur, sans réseau.
 */
beforeEach(function () {
    $this->bin = sys_get_temp_dir().'/faux-bin-'.bin2hex(random_bytes(4));
    mkdir($this->bin);
    $this->journal = $this->bin.'/lftp.log';
    file_put_contents($this->bin.'/lftp', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$2\" >> \"\$FAUX_JOURNAL\"\n");
    file_put_contents($this->bin.'/curl', <<<'SH'
        #!/usr/bin/env bash
        url=""; entetes=0
        for a in "$@"; do
          case "$a" in http*) url="$a" ;; -*I*) entetes=1 ;; esac
        done
        printf 'curl %s\n' "$url" >> "$FAUX_JOURNAL"
        case "$url" in
          */_deploiement/migrer*) printf '%s' "$FAUX_MIGRER"; exit "${FAUX_MIGRER_CODE:-0}" ;;
        esac
        if [ "$entetes" = 1 ]; then printf 'content-security-policy: x\nstrict-transport-security: y\n'; else printf 'ok'; fi
        SH);
    file_put_contents($this->bin.'/sleep', "#!/usr/bin/env bash\nexit 0\n");
    foreach (['lftp', 'curl', 'sleep'] as $outil) {
        chmod($this->bin.'/'.$outil, 0755);
    }
});

afterEach(function () {
    foreach (glob($this->bin.'/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->bin);
});

/** @return array{code: int, sortie: string, journal: string} */
function deployer(object $test, string $siteServi, string $repriseGrr, string $migrer, int $codeMigrer = 0): array
{
    $r = Process::path(base_path())->env([
        'PATH' => $test->bin.':'.getenv('PATH'),
        'FAUX_JOURNAL' => $test->journal,
        'FAUX_MIGRER' => $migrer,
        'FAUX_MIGRER_CODE' => (string) $codeMigrer,
        'SFTP_HOTE' => 'sftp.exemple.test', 'SFTP_UTILISATEUR' => 'compte', 'SFTP_CLE' => 'cle', 'SFTP_EMPREINTE_HOTE' => 'empreinte',
        'SITE_URL' => 'https://site.exemple.test', 'DOSSIER_DISTANT' => 'narbonnaise-v2',
        'SITE_SERVI' => $siteServi, 'REPRISE_GRR' => $repriseGrr, 'GITHUB_SHA' => 'abcdef1234567890',
    ])->run(['bash', 'deploiement/deployer.sh']);

    return ['code' => $r->exitCode() ?? 1, 'sortie' => $r->output().$r->errorOutput(), 'journal' => (string) @file_get_contents($test->journal)];
}

/** Chemins distants touchés par les commandes lftp (put -o, mv, rm, mkdir, destination de mirror). */
function cheminsDistants(string $journal): array
{
    $chemins = [];
    foreach (explode("\n", $journal) as $ligne) {
        foreach (array_map('trim', explode(';', $ligne)) as $commande) {
            $mots = preg_split('/\s+/', $commande) ?: [];
            match ($mots[0] ?? '') {
                'put' => $chemins[] = $mots[array_search('-o', $mots, true) + 1] ?? '',
                'mv' => array_push($chemins, $mots[1] ?? '', $mots[2] ?? ''),
                'rm', 'mkdir' => $chemins[] = (string) end($mots),
                'mirror' => $chemins[] = (string) end($mots),
                default => null,
            };
        }
    }

    return array_values(array_filter($chemins));
}

it('US-805-P1 enchaîne sauvegarde, migrations, import et contrôle, puis sort de maintenance quand le contrôle est validé', function () {
    $r = deployer($this, 'oui', 'oui', '{"migre":true,"reprise":{"valide":true,"archives":{"sauvegarde":20,"export_grr":6}}}');

    expect($r['code'])->toBe(0)
        ->and($r['journal'])->toContain('curl https://site.exemple.test/_deploiement/migrer?reprise=1')
        ->and($r['journal'])->toContain('rm -f narbonnaise-v2/partage/storage/framework/down');
});

it('US-805-N1 s\'arrête avant la mise en service si le contrôle n\'est pas validé : maintenance gardée, consigne de remettre www', function () {
    $r = deployer($this, 'oui', 'oui', '{"migre":true,"reprise":{"valide":false}}');

    $apresMigration = substr($r['journal'], (int) strpos($r['journal'], '_deploiement/migrer'));
    expect($r['code'])->not->toBe(0)
        ->and($r['sortie'])->toContain('Bascule arrêtée')->toContain('dossier racine www')
        ->and($apresMigration)->not->toContain('framework/down')  // la maintenance reste en place
        ->and($apresMigration)->not->toContain('/sante');         // pas de test de fumée
});

it('US-805-N1 s\'arrête aussi quand la sauvegarde, l\'export ou les migrations échouent', function () {
    $r = deployer($this, 'oui', 'oui', '{"migre":false,"etape":"sauvegarde"}', 22);

    $apresMigration = substr($r['journal'], (int) strpos($r['journal'], '_deploiement/migrer'));
    expect($r['code'])->not->toBe(0)
        ->and($r['sortie'])->toContain('Bascule arrêtée')
        ->and($apresMigration)->not->toContain('mv ');
});

it('US-805-N4 n\'écrit jamais hors du dossier du nouveau site, ni dans la configuration PHP de la racine', function () {
    foreach ([['non', 'non', ''], ['oui', 'oui', '{"reprise":{"valide":true}}'], ['oui', 'oui', '{"reprise":{"valide":false}}'], ['oui', 'non', '{"migre":true}']] as [$servi, $reprise, $reponse]) {
        @unlink($this->journal);
        $chemins = cheminsDistants(deployer($this, $servi, $reprise, $reponse)['journal']);

        expect($chemins)->not->toBeEmpty();
        foreach ($chemins as $chemin) {
            expect($chemin)->toStartWith('narbonnaise-v2/')->not->toEndWith('.ovhconfig');
        }
    }

    // Ni le script ni la CI ne mentionnent ce fichier ailleurs qu'en commentaire.
    foreach (['deploiement/deployer.sh', '.github/workflows/ci.yml'] as $fichier) {
        $lignes = array_filter(file(base_path($fichier)) ?: [], fn ($l) => ! str_starts_with(ltrim($l), '#'));
        expect(implode('', $lignes))->not->toContain('ovhconfig');
    }
});

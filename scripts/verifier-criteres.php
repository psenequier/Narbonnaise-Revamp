<?php

/**
 * Vérifie que chaque critère d'acceptation listé dans tests/criteres.txt
 * (export du document des user stories, un identifiant par ligne, ex. US-301-N2)
 * est prouvé par au moins un test dont le nom commence par cet identifiant.
 *
 * Usage : php scripts/verifier-criteres.php [--lot=MVP]
 * Sortie : code 1 s'il manque un test, avec la liste des critères orphelins.
 */

$racine = dirname(__DIR__);
$fichier = $racine.'/tests/criteres.txt';

if (! is_file($fichier)) {
    fwrite(STDERR, "tests/criteres.txt introuvable.\n");
    exit(1);
}

$lot = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--lot=')) {
        $lot = substr($arg, 6);
    }
}

// Format d'une ligne : « US-301-N2 MVP [fonctionnel] » ; les lignes # sont des commentaires.
$attendus = [];
foreach (file($fichier, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $ligne) {
    if (str_starts_with(trim($ligne), '#')) {
        continue;
    }
    [$id, $lotCritere] = array_pad(preg_split('/\s+/', trim($ligne)), 2, '');
    if ($lot === null || $lotCritere === $lot) {
        $attendus[$id] = true;
    }
}

$trouves = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine.'/tests'));
foreach ($it as $f) {
    if ($f->isFile() && str_ends_with($f->getFilename(), 'Test.php')) {
        preg_match_all('/\b(?:it|test)\(\s*[\'"](US-\d{3}-[PN]\d+)\b/', file_get_contents($f->getPathname()), $m);
        foreach ($m[1] as $id) {
            $trouves[$id] = true;
        }
    }
}

$manquants = array_keys(array_diff_key($attendus, $trouves));
sort($manquants);

printf("%d critères attendus, %d prouvés par un test.\n", count($attendus), count($attendus) - count($manquants));

if ($manquants !== []) {
    fwrite(STDERR, "Critères sans test :\n  ".implode("\n  ", $manquants)."\n");
    exit(1);
}

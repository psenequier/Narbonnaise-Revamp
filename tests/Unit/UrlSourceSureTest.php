<?php

use App\Domain\Sources\UrlSourceSure;

beforeEach(function () {
    // Résolveur simulé : aucun appel DNS réel pendant les tests.
    $this->filtre = new UrlSourceSure(fn (string $hote) => match ($hote) {
        'interne.example' => ['10.0.0.5'],
        'localhost' => ['127.0.0.1'],
        'inconnu.example' => [],
        default => ['93.184.216.34'],
    });
});

it('US-607-N1 refuse toute URL en http://', function () {
    $this->filtre->valider('http://api.open-meteo.com/v1/forecast');
})->throws(InvalidArgumentException::class, 'https://');

it('US-607-N2 refuse les adresses internes', function (string $url) {
    $this->filtre->valider($url);
})->throws(InvalidArgumentException::class)->with([
    'https://localhost/',
    'https://127.0.0.1/',
    'https://10.1.2.3/',
    'https://192.168.1.1/',
    'https://169.254.169.254/latest/meta-data',
    'https://[::1]/',
    'https://interne.example/',
]);

it('refuse identifiants, ports non standard et noms introuvables', function (string $url) {
    $this->filtre->valider($url);
})->throws(InvalidArgumentException::class)->with([
    'https://u:p@api.open-meteo.com/',
    'https://api.open-meteo.com:8443/',
    'https://inconnu.example/',
]);

it('accepte les sources officielles préremplies', function (string $url) {
    expect($this->filtre->valider($url))->toBe($url);
})->with([
    'https://api.open-meteo.com/v1/forecast',
    'https://data.education.gouv.fr/api/explore/v2.1/catalog/datasets/fr-en-calendrier-scolaire/records',
]);

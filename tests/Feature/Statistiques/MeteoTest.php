<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo('2026-12-16 09:40:00');
    app()->instance(\App\Domain\Sources\UrlSourceSure::class, new \App\Domain\Sources\UrlSourceSure(fn () => ['185.75.143.24']));
    reinitialiserHttp();
    $this->membre = User::factory()->create();
});

/** Réponse Open-Meteo simplifiée (format de l'API, valeurs d'exemple). */
function reponseMeteo(array $actuel = []): array
{
    return [
        'current' => [
            'temperature_2m' => -3.2, 'apparent_temperature' => -8.1, 'relative_humidity_2m' => 88, 'weather_code' => 71,
            'wind_speed_10m' => 15.4, 'freezing_level_height' => 1120, 'snow_depth' => 0.85, ...$actuel,
        ],
        'daily' => [
            'time' => ['2026-12-16', '2026-12-17', '2026-12-18', '2026-12-19', '2026-12-20', '2026-12-21', '2026-12-22'],
            'weather_code' => [71, 73, 2, 0, 0, 3, 73],
            'temperature_2m_max' => [-1, -3, -2, 1, 3, 2, -2],
            'temperature_2m_min' => [-6, -8, -9, -7, -5, -4, -6],
            'snowfall_sum' => [4, 12, 0, 0, 0, 2, 8],
        ],
    ];
}

function simulerMeteo(array $actuel = []): void
{
    Http::fake(['api.open-meteo.com/*' => Http::response(reponseMeteo($actuel))]);
}

it('US-704-P1 interroge Open-Meteo avec les coordonnées et l\'altitude configurées', function () {
    simulerMeteo();
    $this->actingAs($this->membre)->get('/meteo')->assertOk();

    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.open-meteo.com/v1/forecast')
        && $r['latitude'] === '45.1232' && $r['longitude'] === '5.8773' && $r['elevation'] === '1650');
});

it('US-704-P2 affiche température, ressenti, ciel, vent, isotherme, humidité et 7 jours de prévisions, avec l\'heure', function () {
    simulerMeteo();

    $page = $this->actingAs($this->membre)->get('/meteo')->assertOk()
        ->assertSee('−3 °C')->assertSee('Neige faible')->assertSee('ressenti −8 °C')->assertSee('15 km/h')
        ->assertSee('1 100 m')->assertSee('88 %')->assertSee('à 09:40')
        ->assertSee('+12 cm')->assertSee('Soleil');
    expect(substr_count($page->getContent(), 'data-jour='))->toBe(7);
});

it('US-704-P3 ne fait qu\'un appel pour deux visites dans la même heure', function () {
    simulerMeteo();
    $this->actingAs($this->membre)->get('/meteo');
    $this->travel(20)->minutes();
    $this->actingAs($this->membre)->get('/meteo');
    Http::assertSentCount(1);

    $this->travel(50)->minutes();
    $this->actingAs($this->membre)->get('/meteo');
    Http::assertSentCount(2);
});

it('US-704-N1 garde la dernière valeur connue, avec son heure, si Open-Meteo échoue ou renvoie du JSON invalide', function (callable $panne) {
    simulerMeteo();
    $this->actingAs($this->membre)->get('/meteo')->assertSee('−3 °C');

    $this->travel(2)->hours();
    reinitialiserHttp();
    Http::fake(['api.open-meteo.com/*' => $panne()]);
    $this->actingAs($this->membre)->get('/meteo')->assertOk()->assertSee('−3 °C')->assertSee('à 09:40');
})->with([
    'erreur 500' => fn () => fn () => Http::response('', 500),
    'JSON invalide' => fn () => fn () => Http::response('{pas du json', 200),
    'schéma inattendu' => fn () => fn () => Http::response(['current' => 'rien'], 200),
]);

it('US-704-N2 affiche « Météo indisponible » sans aucune valeur connue', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response('', 503)]);

    $this->actingAs($this->membre)->get('/meteo')->assertOk()->assertSee('Météo indisponible');
});

it('US-704-N3 le navigateur n\'appelle aucun domaine tiers : CSP limitée au site, pictogrammes compris', function () {
    simulerMeteo();
    $reponse = $this->actingAs($this->membre)->get('/meteo');

    expect($reponse->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")->toContain("img-src 'self' data:")
        ->and($reponse->getContent())->not->toMatch('/<(img|script|link)[^>]+(src|href)="https?:\/\/(?!localhost)/');
});

it('US-704-N4 accompagne chaque pictogramme du texte du ciel ; un code inconnu donne « Ciel inconnu »', function () {
    simulerMeteo(['weather_code' => 42]);

    $this->actingAs($this->membre)->get('/meteo')->assertOk()->assertSee('Ciel inconnu')->assertSee('data-picto="inconnu"', false);
});

it('US-705-P1 affiche la neige au sol en cm, avec la mention « estimation du modèle météo »', function () {
    simulerMeteo();

    $this->actingAs($this->membre)->get('/meteo')->assertSee('85 cm')->assertSee('estimation du modèle météo');
});

it('US-705-P2 pointe vers les pages configurées pour les pistes et les webcams, avec rel="noopener"', function () {
    simulerMeteo();

    $this->actingAs($this->membre)->get('/meteo')
        ->assertSee('href="https://www.chamrousse.com/ouverture-alpin-piste-remontee.html" target="_blank" rel="noopener noreferrer"', false)
        ->assertSee('href="https://www.chamrousse.com/webcams.html" target="_blank" rel="noopener noreferrer"', false);
});

it('US-705-N1 affiche « Donnée indisponible » pour une hauteur de neige absente ou négative', function (mixed $hauteur) {
    simulerMeteo(['snow_depth' => $hauteur]);

    $this->actingAs($this->membre)->get('/meteo')->assertSee('Donnée indisponible');
})->with([null, -0.2]);

it('n\'affiche plus de nombre de pistes ni de remontées ouvertes (US-706 abandonnée)', function () {
    simulerMeteo();

    $this->actingAs($this->membre)->get('/meteo')->assertDontSee('Pistes ouvertes')->assertDontSee('Remontées ouvertes');
});

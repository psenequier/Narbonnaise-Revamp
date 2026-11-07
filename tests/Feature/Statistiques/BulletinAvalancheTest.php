<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-12-18 18:00:00', 'Europe/Paris'));
    app()->instance(\App\Domain\Sources\UrlSourceSure::class, new \App\Domain\Sources\UrlSourceSure(fn () => ['185.75.143.24']));
    config(['services.meteofrance.cle' => 'cle-de-test-123']);
    reinitialiserHttp();
    $this->membre = User::factory()->create();
});

/** Bulletin BRA fictif au format XML de Météo-France. */
function braXml(array $risque = [], string $enneigement = '<ENNEIGEMENT DATE="2026-12-18" LimiteSud="1300" LimiteNord="1100"><NIVEAU ALTI="1500" N="35" S="15"/><NIVEAU ALTI="2500" N="140" S="95"/><NIVEAU ALTI="2000" N="85"/></ENNEIGEMENT>', string $echeance = '2026-12-19T16:00:00'): string
{
    $r = ['RISQUE1' => '2', 'ALTITUDE' => '2000', 'RISQUE2' => '3', 'RISQUEMAXI' => '3', 'RISQUEMAXIJ2' => '3', ...$risque];
    $attrs = implode(' ', array_map(fn ($k, $v) => $k.'="'.$v.'"', array_keys($r), $r));

    return <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <BULLETINS_NEIGE_AVALANCHE TYPEBULLETIN="BRA" ID="8" MASSIF="BELLEDONNE" DATEBULLETIN="2026-12-18T16:00:00" DATEECHEANCE="{$echeance}">
    <CARTOUCHERISQUE><RISQUE {$attrs}/><RESUME>Plaques à vent au-dessus de 2000 m en versants nord et est.</RESUME></CARTOUCHERISQUE>
    {$enneigement}
    </BULLETINS_NEIGE_AVALANCHE>
    XML;
}

function simulerBra(?string $xml = null, int $statut = 200): void
{
    Http::fake([
        'public-api.meteofrance.fr/*' => Http::response($xml ?? braXml(), $statut, ['Content-Type' => 'application/xml']),
        'api.open-meteo.com/*' => Http::response('', 503),
        'mto38.free.fr/*' => Http::response('', 503),
    ]);
}

it('US-708-P1 affiche les niveaux au-dessus et en dessous de la limite, la date du bulletin et sa validité', function () {
    simulerBra();

    $this->actingAs($this->membre)->get('/meteo')->assertOk()
        ->assertSee('Risque d\'avalanche · Belledonne', false)
        ->assertSeeInOrder(['3 · Marqué', 'au-dessus de 2000 m', '2 · Limité', 'en dessous de 2000 m'])
        ->assertSee('Bulletin du 18/12 à 16h00, valable jusqu\'au 19/12 à 16h00', false)
        ->assertSee('Plaques à vent');
    Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://public-api.meteofrance.fr/public/DPBRA/v1/massif/BRA') && $r['id-massif'] === '8');
});

it('US-708-P1 affiche un seul niveau quand le bulletin ne distingue pas d\'altitude', function () {
    simulerBra(braXml(['RISQUE2' => '-1', 'ALTITUDE' => '']));

    $page = $this->actingAs($this->membre)->get('/meteo')->assertOk()->assertSee('2 · Limité')->getContent();
    expect($page)->not->toContain('en dessous de')->and(substr_count($page, 'data-niveau='))->toBe(1);
});

it('US-708-P2 écrit chaque niveau en chiffre et en mot, avec l\'échelle européenne et le niveau du jour signalé', function () {
    simulerBra();

    $page = $this->actingAs($this->membre)->get('/meteo')->assertOk()
        ->assertSeeInOrder(['1 Faible', '2 Limité', '3 Marqué', '4 Fort', '5 Très fort'])->getContent();
    expect($page)->toContain('data-picto-avalanche="3"')->toContain('fill="#FF9900"')
        ->and($page)->toMatch('#<li class="[^"]*bg-\[\#FF9900\][^"]*outline-2[^"]*"\s+aria-current="true"\s*>3 Marqué</li>#');
});

it('US-708-P3 rappelle la lecture du bulletin complet et donne le lien configuré', function () {
    simulerBra();

    $page = $this->actingAs($this->membre)->get('/meteo')->assertOk()->assertSee('Ne remplace pas la lecture du bulletin complet', false)->getContent();
    expect($page)->toContain('href="https://meteofrance.com/meteo-montagne/alpes-du-nord/risques-avalanche" target="_blank" rel="noopener noreferrer"');
});

it('US-708-P4 lit le bulletin côté serveur au plus une fois par heure, sans jamais montrer la clé', function () {
    simulerBra();
    $appels = fn () => Http::recorded(fn ($r) => str_contains($r->url(), 'public-api.meteofrance.fr'))->count();

    $page = $this->actingAs($this->membre)->get('/meteo')->getContent();
    $this->travel(30)->minutes();
    $this->actingAs($this->membre)->get('/meteo');
    expect($appels())->toBe(1)->and($page)->not->toContain('cle-de-test-123');
    Http::assertSent(fn (Request $r) => $r->hasHeader('apikey', 'cle-de-test-123'));

    $this->travel(31)->minutes();
    $this->actingAs($this->membre)->get('/meteo');
    expect($appels())->toBe(2);
});

it('US-708-N1 affiche « Pas de bulletin d\'avalanche en ce moment » sans clé, hors saison ou bulletin échu', function (string $cas) {
    match ($cas) {
        'sans clé' => config(['services.meteofrance.cle' => null]),
        'hors saison' => null,
        'échu' => null,
    };
    simulerBra($cas === 'échu' ? braXml([], echeance: '2026-12-17T16:00:00') : ($cas === 'hors saison' ? '' : null), $cas === 'hors saison' ? 404 : 200);

    $this->actingAs($this->membre)->get('/meteo')->assertOk()
        ->assertSee('Pas de bulletin d\'avalanche en ce moment', false)
        ->assertSee('href="https://meteofrance.com/meteo-montagne/alpes-du-nord/risques-avalanche"', false);
})->with(['sans clé', 'hors saison', 'échu']);

it('US-708-N1 n\'appelle pas Météo-France sans clé', function () {
    config(['services.meteofrance.cle' => '']);
    Http::fake(['api.open-meteo.com/*' => Http::response('', 503), 'mto38.free.fr/*' => Http::response('', 503)]);

    $this->actingAs($this->membre)->get('/meteo')->assertOk();
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'meteofrance'));
});

it('US-708-N2 garde le dernier bulletin non échu si le portail ne répond pas', function () {
    simulerBra();
    $this->actingAs($this->membre)->get('/meteo')->assertSee('3 · Marqué');

    $this->travel(2)->hours();
    reinitialiserHttp();
    simulerBra('', 500);
    $this->actingAs($this->membre)->get('/meteo')->assertOk()->assertSee('3 · Marqué');
});

it('US-708-N3 n\'affiche jamais une réponse illisible ou un niveau hors de 1 à 5', function (string $xml) {
    simulerBra($xml);

    $this->actingAs($this->membre)->get('/meteo')->assertOk()->assertSee('Pas de bulletin d\'avalanche en ce moment', false)->assertDontSee('data-niveau=', false);
})->with([
    'XML invalide' => '<BULLETINS_NEIGE_AVALANCHE><pas fermé',
    'niveau 7' => braXml(['RISQUE1' => '7']),
    'niveau absent' => braXml(['RISQUE1' => '']),
    'autre document' => '<?xml version="1.0"?><erreur>quota</erreur>',
]);

it('US-709-P1 montre la hauteur de neige par altitude, de la plus haute à la plus basse, nord et sud', function () {
    simulerBra();

    $page = $this->actingAs($this->membre)->get('/meteo')->assertOk()
        ->assertSee('Enneigement · Belledonne')
        ->assertSeeInOrder(['2 500 m', '140 cm', '95 cm', '2 000 m', '85 cm', '1 500 m', '35 cm', '15 cm'])->getContent();
    expect(substr_count($page, 'data-altitude='))->toBe(3);
});

it('US-709-P2 affiche la limite d\'enneigement au nord et au sud', function () {
    simulerBra();

    $this->actingAs($this->membre)->get('/meteo')->assertOk()->assertSee('1 100 m au nord · 1 300 m au sud', false);
});

it('US-709-N1 affiche « – » pour une hauteur absente et « Donnée indisponible » sans enneigement', function () {
    simulerBra();
    $page = $this->actingAs($this->membre)->get('/meteo')->getContent();
    expect($page)->toMatch('#data-altitude="2000".*?<b>85 cm</b>.*?<b>–</b>#s');

    \Illuminate\Support\Facades\Cache::flush();
    reinitialiserHttp();
    simulerBra(braXml(enneigement: ''));
    $this->actingAs($this->membre)->get('/meteo')->assertOk()->assertSee('3 · Marqué')->assertSee('Donnée indisponible');
});

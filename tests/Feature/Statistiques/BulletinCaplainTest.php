<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-12-16 09:40:00', 'Europe/Paris'));
    app()->instance(\App\Domain\Sources\UrlSourceSure::class, new \App\Domain\Sources\UrlSourceSure(fn () => ['185.75.143.24']));
    reinitialiserHttp();
    $this->membre = User::factory()->create();
});

/** Page fictive au format de mto38.free.fr (texte inventé), encodée en Windows-1252 comme l'originale. */
function pageCaplain(string $situation = 'Froid sec et ciel dégagé.', string $extra = ''): string
{
    $html = <<<HTML
    <html><head><meta http-equiv="Content-Type" content="text/html; charset=windows-1252"><title>Calepin</title></head>
    <body><table border="1"><tr><td><pre>Aube 07:40</pre></td></tr></table>
    <table border="1" bgcolor="#DDEEFF" class="forecast">
    <tbody><tr><th>Alpes du Nord (France)
    </th></tr><tr><td>
    <b>Emis <i>Mardi 15 Décembre 2026, 21:30</i></b> (<a href="horaire.html">horaire usuel</a>)
    <div class="preface"></div>
    <div class="titre">Situation et évolution</div><div class="blabla">
    {$situation}
    </div>
    <div class="titre">demain Mercredi 16 Décembre:</div><div class="blabla">
    Neige faible le matin au-dessus de 900m, éclaircies l'après-midi.{$extra}
    </div>
    <div class="donnees">
    pression 1018-&gt;1024 mb
    <a class="info" href="#">en plaine<span>bassin grenoblois</span></a>: -2/4°C, sans vent
    iso 0°: 800-&gt;1200m
    </div>
    <div class="lafotaki">signé: la <a href="mailto:x@example.test">Grenouille</a></div>
    </td></tr></tbody></table>
    <small>Bitcoin 1ABC</small></body></html>
    HTML;

    return mb_convert_encoding($html, 'Windows-1252', 'UTF-8');
}

function simulerCaplain(?string $page = null): void
{
    Http::fake([
        'api.open-meteo.com/*' => Http::response('', 503),
        'mto38.free.fr/*' => Http::response($page ?? pageCaplain(), 200, ['Content-Type' => 'text/html; charset=windows-1252']),
    ]);
}

it('US-707-P1 affiche sous la météo la date d\'émission, la situation, chaque jour et ses données', function () {
    simulerCaplain();

    $this->actingAs($this->membre)->get('/meteo')->assertOk()
        ->assertSee('Le bulletin de M. Caplain · Alpes du Nord (France)')
        ->assertSee('Émis le Mardi 15 Décembre 2026, 21:30')
        ->assertSeeInOrder(['Situation et évolution', 'Froid sec et ciel dégagé.', 'Demain mercredi 16 décembre', 'Neige faible le matin', 'Pression 1018 → 1024 mb', 'En plaine: -2/4°C, sans vent', 'Iso 0°: 800 → 1200m']);
});

it('US-707-P2 relit la page côté serveur au plus une fois par demi-heure', function () {
    simulerCaplain();
    $caplain = fn () => Http::recorded(fn ($r) => str_starts_with($r->url(), 'http://mto38.free.fr/'))->count();

    $this->actingAs($this->membre)->get('/meteo');
    $this->travel(20)->minutes();
    $this->actingAs($this->membre)->get('/meteo');
    expect($caplain())->toBe(1);

    $this->travel(15)->minutes();
    $this->actingAs($this->membre)->get('/meteo');
    expect($caplain())->toBe(2);
});

it('US-707-P3 remercie M. Caplain, cite la source et donne le lien', function () {
    simulerCaplain();

    $page = $this->actingAs($this->membre)->get('/meteo')->assertOk()->getContent();
    expect($page)->toContain('Bulletin rédigé par M. Caplain, que nous remercions')
        ->and($page)->toMatch('#<a href="http://mto38.free.fr/" target="_blank" rel="noopener noreferrer"[^>]*>mto38.free.fr</a>#');
});

it('US-707-N1 garde le dernier bulletin connu si le site ne répond pas ou change de structure', function (callable $panne) {
    simulerCaplain();
    $this->actingAs($this->membre)->get('/meteo')->assertSee('Froid sec');

    $this->travel(1)->hours();
    reinitialiserHttp();
    Http::fake(['api.open-meteo.com/*' => Http::response('', 503), 'mto38.free.fr/*' => $panne()]);
    $this->actingAs($this->membre)->get('/meteo')->assertOk()->assertSee('Froid sec')->assertSee('Émis le Mardi 15 Décembre 2026');
})->with([
    'erreur 500' => fn () => fn () => Http::response('', 500),
    'délai dépassé' => fn () => fn () => throw new \Illuminate\Http\Client\ConnectionException('délai'),
    'tableau introuvable' => fn () => fn () => Http::response('<html><body><p>Travaux</p></body></html>', 200),
]);

it('US-707-N1 affiche « Bulletin indisponible » et le lien sans aucun bulletin connu', function () {
    Http::fake(['api.open-meteo.com/*' => Http::response('', 503), 'mto38.free.fr/*' => Http::response('', 500)]);

    $this->actingAs($this->membre)->get('/meteo')->assertOk()
        ->assertSee('Bulletin indisponible')->assertSee('href="http://mto38.free.fr/"', false);
});

it('US-707-N2 ne reprend que le texte, échappé : ni balise, ni script, ni lien de la page d\'origine', function () {
    simulerCaplain(pageCaplain('Beau <script>alert(1)</script> temps <img src=x onerror=alert(2)>', ' <a href="https://piege.example.test">ici</a>'));

    $page = $this->actingAs($this->membre)->get('/meteo')->assertOk()->getContent();
    expect($page)->not->toContain('<script>alert')
        ->and($page)->not->toContain('onerror=')
        ->and($page)->not->toContain('piege.example.test')
        ->and($page)->not->toContain('Bitcoin')
        ->and($page)->not->toContain('Grenouille')
        ->and($page)->not->toContain('bassin grenoblois');
});

it('US-707-N3 convertit la page Windows-1252 : accents et degrés corrects', function () {
    simulerCaplain();

    $page = $this->actingAs($this->membre)->get('/meteo')->assertOk()->getContent();
    expect($page)->toContain('Situation et évolution')->toContain('-2/4°C')->toContain('éclaircies')
        ->and(mb_check_encoding($page, 'UTF-8'))->toBeTrue();
});

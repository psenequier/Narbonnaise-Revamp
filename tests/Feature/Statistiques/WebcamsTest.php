<?php

use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-07 15:00:00', 'Europe/Paris'));
    app()->instance(\App\Domain\Sources\UrlSourceSure::class, new \App\Domain\Sources\UrlSourceSure(fn () => ['185.75.143.24']));
    Storage::fake('local');
    reinitialiserHttp();
    $this->membre = User::factory()->create();
});

/** Image JPEG de 1 × 1 pixel. */
function jpegMinuscule(): string
{
    return (string) base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');
}

/** Page fictive au format de Skaping : seule la balise og:image compte. */
function pageSkaping(string $image): string
{
    return '<html><head><title>Webcam</title><meta property="og:image" content="'.$image.'"><meta name="twitter:card" content="summary"></head><body><div id="app"></div></body></html>';
}

/**
 * Simule Skaping. $images : adresse donnée par chaque page (null = page en panne) ;
 * $corps : contenu servi pour les images.
 */
function simulerSkaping(?string $croix = 'https://skaping.s3.gra.io.cloud.ovh.net/chamrousse/la-croix/2026/11/07/large/14-50.jpg',
    ?string $recoin = 'https://skaping.s3.gra.io.cloud.ovh.net/chamrousse-front-de-neige/snap/2026/11/07/large/14-50.jpg',
    ?string $corps = null): void
{
    reinitialiserHttp();
    Http::fake(function (Request $r) use ($croix, $recoin, $corps) {
        $url = $r->url();
        if (str_starts_with($url, 'https://www.skaping.com/chamrousse/la-croix')) {
            return $croix === null ? Http::response('', 503) : Http::response(pageSkaping($croix));
        }
        if (str_starts_with($url, 'https://www.skaping.com/chamrousse/recoin')) {
            return $recoin === null ? Http::response('', 503) : Http::response(pageSkaping($recoin));
        }

        return Http::response($corps ?? jpegMinuscule(), 200, ['Content-Type' => 'image/jpeg']);
    });
}

it('US-710-P1 montre La Croix et Le Recoin avec la dernière image, son heure et les liens', function () {
    simulerSkaping();

    $page = $this->actingAs($this->membre)->get('/webcams')->assertOk()
        ->assertSeeInOrder(['La Croix', 'Sommet · 2 250 m', 'Image de 14 h 50', 'Le Recoin', 'Front de neige · 1 650 m', 'Image de 14 h 50'])
        ->assertSee('href="https://www.skaping.com/chamrousse/la-croix" target="_blank" rel="noopener noreferrer"', false)
        ->assertSee('href="https://www.skaping.com/chamrousse/recoin/archives" target="_blank" rel="noopener noreferrer"', false)
        ->assertSee('href="https://www.chamrousse.com/webcams.html" target="_blank" rel="noopener noreferrer"', false)
        ->assertSee('Panorama et archives (Skaping)')
        ->assertSee('href="'.route('webcams').'"', false); // onglet du menu
    expect(substr_count((string) $page->getContent(), 'src="'.route('webcams').'/'))->toBe(2);

    $image = $this->actingAs($this->membre)->get('/webcams/la-croix/image')->assertOk();
    expect($image->headers->get('Content-Type'))->toBe('image/jpeg');
});

it('US-710-P2 relit chaque page au plus une fois par 10 minutes ; le navigateur n\'appelle pas Skaping', function () {
    simulerSkaping();
    $pages = fn () => Http::recorded(fn ($r) => str_starts_with($r->url(), 'https://www.skaping.com/'))->count();

    $page = $this->actingAs($this->membre)->get('/webcams');
    $this->travel(5)->minutes();
    $this->actingAs($this->membre)->get('/webcams');
    expect($pages())->toBe(2); // une lecture par webcam

    $this->travel(6)->minutes();
    $this->actingAs($this->membre)->get('/webcams');
    expect($pages())->toBe(4)
        ->and((string) $page->getContent())->not->toContain('skaping.s3')->not->toContain('src="https://www.skaping');
    Storage::disk('local')->assertExists('webcams/la-croix.jpg');
});

it('US-710-P3 cite la source en italique', function () {
    simulerSkaping();

    $contenu = (string) $this->actingAs($this->membre)->get('/webcams')->getContent();
    expect($contenu)->toMatch('/<p class="[^"]*\bitalic\b[^"]*" data-credit-webcams>Images : Skaping et station de Chamrousse\.<\/p>/u');
});

it('US-710-N1 garde la dernière image si la webcam ne répond plus, la marque ancienne après 2 h', function () {
    simulerSkaping();
    $this->actingAs($this->membre)->get('/webcams');

    simulerSkaping(croix: null, recoin: null);
    $this->travel(30)->minutes();
    $this->actingAs($this->membre)->get('/webcams')->assertOk()->assertSee('Image de 14 h 50')->assertDontSee('Image ancienne');

    $this->travel(2)->hours();
    $this->actingAs($this->membre)->get('/webcams')->assertOk()
        ->assertSee('Image ancienne : 14 h 50')->assertDontSee('Webcam indisponible');
});

it('US-710-N1 affiche « Webcam indisponible » et garde le lien sans aucune image', function () {
    simulerSkaping(croix: null, recoin: null);

    $this->actingAs($this->membre)->get('/webcams')->assertOk()
        ->assertSee('Webcam indisponible')
        ->assertSee('href="https://www.skaping.com/chamrousse/la-croix"', false);
    $this->actingAs($this->membre)->get('/webcams/la-croix/image')->assertNotFound();
});

it('US-710-N2 refuse une image hors https, hors Skaping, qui n\'est pas une image ou trop lourde', function (string $cas) {
    $bonne = 'https://skaping.s3.gra.io.cloud.ovh.net/chamrousse/la-croix/2026/11/07/large/14-50.jpg';
    match ($cas) {
        'http' => simulerSkaping(croix: 'http://skaping.s3.gra.io.cloud.ovh.net/chamrousse/la-croix/2026/11/07/large/14-50.jpg', recoin: null),
        'autre hôte' => simulerSkaping(croix: 'https://images.example.test/14-50.jpg', recoin: null),
        'pas une image' => simulerSkaping(croix: $bonne, recoin: null, corps: '<script>alert(1)</script>'),
        'trop lourde' => simulerSkaping(croix: $bonne, recoin: null, corps: jpegMinuscule().str_repeat('0', 5 * 1024 * 1024)),
    };

    $this->actingAs($this->membre)->get('/webcams')->assertOk()->assertSee('Webcam indisponible');
    Storage::disk('local')->assertMissing('webcams/la-croix.jpg');
    if (in_array($cas, ['http', 'autre hôte'], true)) {
        expect(Http::recorded(fn ($r) => ! str_starts_with($r->url(), 'https://www.skaping.com/'))->count())->toBe(0);
    }
})->with(['http', 'autre hôte', 'pas une image', 'trop lourde']);

it('US-710-N2 ne sert les images qu\'aux membres connectés', function () {
    simulerSkaping();
    $this->actingAs($this->membre)->get('/webcams');
    auth()->logout();

    $this->get('/webcams/la-croix/image')->assertRedirect();
    $this->get('/webcams/inconnue/image')->assertRedirect();
});

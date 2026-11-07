<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Meteo\Webcams;
use App\Domain\Parametres;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/** Webcams de la station (US-710) : les images sont servies par le site, jamais par un tiers. */
final class WebcamsController
{
    public function index(Webcams $webcams, Parametres $parametres): View
    {
        return view('webcams', [
            'webcams' => $webcams->lire(),
            'lienStation' => $parametres->lire('station.webcams'),
        ]);
    }

    /** US-710-N2 : réservé aux membres connectés (route sous auth). */
    public function image(string $cle): Response
    {
        $fichier = array_key_exists($cle, Webcams::LISTE) ? Webcams::fichier($cle) : null;
        abort_if($fichier === null, 404);

        return response()->file(Storage::disk('local')->path($fichier), [
            'Content-Type' => str_ends_with($fichier, '.png') ? 'image/png' : 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}

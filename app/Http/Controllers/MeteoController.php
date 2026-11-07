<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Meteo\BulletinAvalanche;
use App\Domain\Meteo\BulletinCaplain;
use App\Domain\Meteo\MeteoRecoin;
use App\Domain\Parametres;
use Illuminate\View\View;

/** Météo et neige au Recoin (F12) : le navigateur n'appelle aucun tiers (US-704-N3). */
final class MeteoController
{
    public function __invoke(MeteoRecoin $meteo, BulletinCaplain $caplain, BulletinAvalanche $avalanche, Parametres $parametres): View
    {
        return view('meteo', [
            'm' => $meteo->lire(),
            'bulletin' => $caplain->lire(),
            'bra' => $avalanche->lire(),
            'lienAvalanche' => $parametres->lire('station.bulletin_avalanche'),
            'latitude' => $parametres->lire('station.latitude'),
            'longitude' => $parametres->lire('station.longitude'),
            'altitude' => $parametres->lire('station.altitude'),
            'lienPistes' => $parametres->lire('station.bulletin_neige'),
        ]);
    }
}

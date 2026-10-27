<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * BRANCHE TÉMOIN — NE JAMAIS FUSIONNER.
 * Failles volontaires : la CI doit échouer sur chacune (US-903, US-904, US-901).
 */
final class TemoinController
{
    // US-903-N1 : injection SQL par concaténation d'une saisie (TaintedSql, Semgrep).
    public function recherche(Request $request): mixed
    {
        return DB::select("SELECT * FROM users WHERE nom = '".$request->input('nom')."'");
    }

    // US-903-N3 : appel HTTP vers une URL saisie, sans filtre anti-SSRF (TaintedSSRF, Semgrep).
    public function apercu(Request $request): string
    {
        return Http::get($request->input('url'))->body();
    }

    // US-903-N2 : affichage non échappé d'une saisie (TaintedHtml, Semgrep).
    public function salut(Request $request): mixed
    {
        return view('temoin.salut', ['nom' => $request->input('nom')]);
    }
}

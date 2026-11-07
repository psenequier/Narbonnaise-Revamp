@extends('layouts.app')
@section('titre', 'Météo')
@section('large', '1')
@section('sans-titre', '1')
@php
    \Carbon\Carbon::setLocale('fr');
    $deg = fn (?float $v) => $v === null ? '–' : str_replace('-', '−', (string) round($v)).'°';
    $coord = fn (string $v) => str_replace('.', ',', $v);
    $lien = 'flex h-11 items-center rounded-[10px] border-[1.5px] border-sapin px-4 text-sm font-bold text-sapin no-underline';
@endphp
@section('contenu')
<h1 class="sr-only">Météo et neige au Recoin</h1>
@if ($m === null)
    <p role="status" class="rounded-2xl border border-bord bg-white p-5 text-lg font-bold" data-meteo-indisponible>Météo indisponible</p>
    <p class="text-sm text-gris">La source n'a pas pu être lue. Réessayez dans quelques minutes.</p>
@else
    @php($a = $m['actuel'])
    @php($ciel = \App\Domain\Meteo\MeteoRecoin::ciel($a['code']))
    @php($maj = \Carbon\Carbon::createFromTimestamp($m['le'])->timezone('Europe/Paris'))
    @php($neigeSol = $a['neige_sol'] !== null && $a['neige_sol'] >= 0 ? (int) round($a['neige_sol'] * 100) : null)
    @php($chutes = array_sum(array_map(fn ($j) => (float) ($j['neige'] ?? 0), $m['jours'])))
    <div class="flex flex-wrap gap-5">
        <section class="flex flex-[1_1_380px] flex-col gap-4 rounded-2xl bg-sapin p-6 text-white" aria-labelledby="actuel" data-actuel>
            <div class="flex flex-col gap-0.5">
                <h2 id="actuel" class="text-xl font-extrabold">Chamrousse · Le Recoin</h2>
                <span class="text-sm text-accent">{{ $coord($latitude) }} N · {{ $coord($longitude) }} E · {{ number_format((int) $altitude, 0, ',', ' ') }} m · mis à jour le {{ $maj->format('d/m') }} à {{ $maj->format('H:i') }}</span>
            </div>
            <div class="flex items-center gap-4">
                @include('partiels.picto-meteo', ['icone' => $ciel['icone'], 'taille' => 64])
                <div class="flex flex-col">
                    <span class="text-5xl leading-none font-extrabold" data-temperature>{{ $a['temperature'] === null ? '–' : str_replace('-', '−', (string) round($a['temperature'])) }} °C</span>
                    <span class="text-accent">{{ $ciel['texte'] }}@if ($a['ressenti'] !== null) · ressenti {{ str_replace('-', '−', (string) round($a['ressenti'])) }} °C @endif</span>
                </div>
            </div>
            <dl class="grid grid-cols-3 gap-2.5 text-sm">
                <div class="rounded-[10px] bg-principale-fonce p-2.5"><dt class="text-accent">Vent</dt><dd class="text-lg font-extrabold">{{ $a['vent'] === null ? '–' : round($a['vent']).' km/h' }}</dd></div>
                <div class="rounded-[10px] bg-principale-fonce p-2.5"><dt class="text-accent">Iso 0 °C</dt><dd class="text-lg font-extrabold">{{ $a['iso0'] === null ? '–' : number_format((int) (round($a['iso0'] / 50) * 50), 0, ',', ' ').' m' }}</dd></div>
                <div class="rounded-[10px] bg-principale-fonce p-2.5"><dt class="text-accent">Humidité</dt><dd class="text-lg font-extrabold">{{ $a['humidite'] === null ? '–' : round($a['humidite']).' %' }}</dd></div>
            </dl>
        </section>

        <section class="flex flex-[1_1_380px] flex-col gap-4 rounded-2xl border border-bord bg-white p-6" aria-labelledby="enneigement">
            <h2 id="enneigement" class="text-lg font-extrabold">Enneigement</h2>
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-fond p-3.5" data-neige-sol><div class="text-sm text-gris">Neige au sol (estimation du modèle météo)</div><div class="text-3xl font-extrabold">{{ $neigeSol === null ? 'Donnée indisponible' : $neigeSol.' cm' }}</div></div>
                <div class="rounded-xl bg-fond p-3.5" data-chutes><div class="text-sm text-gris">Chutes prévues 7 jours</div><div class="text-3xl font-extrabold">{{ (int) round($chutes) }} cm</div></div>
            </div>
            <p class="text-sm text-gris">Neige au sol : estimation du modèle météo, pas une mesure. Pour la hauteur mesurée, l'ouverture des pistes et des remontées, voir le site officiel de la station.</p>
            <div class="flex flex-wrap gap-2.5">
                <a href="{{ $lienPistes }}" target="_blank" rel="noopener noreferrer" class="{{ $lien }}">Pistes et remontées ouvertes (site officiel) ↗</a>
                <a href="{{ $lienWebcams }}" target="_blank" rel="noopener noreferrer" class="{{ $lien }}">Webcams ↗</a>
            </div>
        </section>
    </div>

    <section class="flex flex-col gap-3.5 rounded-2xl border border-bord bg-white p-6" aria-labelledby="previsions">
        <h2 id="previsions" class="text-lg font-extrabold">Prévisions 7 jours</h2>
        <div class="overflow-x-auto">
            <ol class="grid min-w-[770px] grid-cols-7 gap-2.5">
                @foreach ($m['jours'] as $i => $j)
                    @php($cj = \App\Domain\Meteo\MeteoRecoin::ciel($j['code']))
                    <li class="flex flex-col items-center gap-2 rounded-xl border border-bord p-3.5 text-sapin" data-jour="{{ $j['date'] }}">
                        <span class="text-sm font-bold text-encre">{{ $i === 0 ? 'Auj.' : ucfirst(\Carbon\Carbon::parse($j['date'])->translatedFormat('D')) }}</span>
                        @include('partiels.picto-meteo', ['icone' => $cj['icone']])
                        <span class="text-sm text-texte-2">{{ $cj['texte'] }}</span>
                        <span class="text-encre"><b>{{ $deg($j['max']) }}</b> / <span class="text-gris">{{ $deg($j['min']) }}</span></span>
                        <span class="rounded-md bg-sapin-clair px-2 text-xs font-bold text-sapin">{{ $j['neige'] === null ? '–' : ($j['neige'] > 0 ? '+' : '').round($j['neige']).' cm' }}</span>
                    </li>
                @endforeach
            </ol>
        </div>
        <p class="text-xs text-gris">Source : Open-Meteo, coordonnées du Recoin, altitude {{ number_format((int) $altitude, 0, ',', ' ') }} m.</p>
    </section>
@endif
@endsection

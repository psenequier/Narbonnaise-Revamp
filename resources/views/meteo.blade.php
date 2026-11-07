@extends('layouts.app')
@section('titre', 'Météo')
@section('large', '1')
@section('sans-titre', '1')
@php
    \Carbon\Carbon::setLocale('fr');
    $deg = fn (?float $v) => $v === null ? '–' : str_replace('-', '−', (string) round($v)).'°';
    $coord = fn (string $v) => str_replace('.', ',', $v);
    $lien = 'flex h-11 items-center rounded-[10px] border-[1.5px] border-sapin px-4 text-sm font-bold text-sapin no-underline';
    // Échelle européenne du risque d'avalanche, gardée malgré la charte (décision du 7 nov. 2026).
    $echelle = [1 => ['#CCFF66', 'bg-[#CCFF66] text-encre'], 2 => ['#FFFF00', 'bg-[#FFFF00] text-encre'], 3 => ['#FF9900', 'bg-[#FF9900] text-encre'], 4 => ['#FF0000', 'bg-[#FF0000] text-white'], 5 => ['#23302A', 'bg-encre text-white']];
    $noms = \App\Domain\Meteo\BulletinAvalanche::NOMS;
    $massif = $bra !== null && $bra['massif'] !== '' ? $bra['massif'] : 'Belledonne';
    $heure = fn (string $iso) => \Carbon\Carbon::parse($iso)->timezone('Europe/Paris')->format('d/m à H\\hi');
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

{{-- US-708, US-709 : bulletin d'avalanche de Météo-France, massif réglable (Belledonne). --}}
<div class="flex flex-wrap gap-5">
    <section class="flex flex-[1_1_380px] flex-col gap-3.5 rounded-2xl border border-bord bg-white p-6" aria-labelledby="avalanche" data-avalanche>
        <div class="flex flex-wrap items-baseline justify-between gap-2.5">
            <h2 id="avalanche" class="text-lg font-extrabold">Risque d'avalanche · {{ $massif }}</h2>
            @if ($bra !== null)
                <span class="text-xs text-gris" data-bra-date>Bulletin du {{ $heure($bra['emis']) }}, valable jusqu'au {{ $heure($bra['echeance']) }}</span>
            @endif
        </div>
        @if ($bra === null)
            <p role="status" class="font-bold" data-bra-absent>Pas de bulletin d'avalanche en ce moment</p>
            <p class="text-sm text-texte-2">Météo-France le publie en saison, à peu près de mi-décembre à fin avril.</p>
        @else
            @php($haut = $bra['niveaux'][0])
            @php($bas = $bra['niveaux'][1] ?? null)
            <div class="flex items-center gap-3.5">
                <svg width="72" height="64" viewBox="0 0 72 64" aria-hidden="true" data-picto-avalanche="{{ $haut['niveau'] }}">
                    <path d="M4 60 L36 6 L68 60 Z" fill="{{ $echelle[$haut['niveau']][0] }}" stroke="#23302A" stroke-width="2" stroke-linejoin="round"/>
                    @if ($bas !== null)
                        <path d="M14 44 H58" stroke="#23302A" stroke-width="2" stroke-dasharray="4 3"/>
                        <text x="36" y="38" text-anchor="middle" font-size="18" font-weight="800" fill="{{ $haut['niveau'] >= 4 ? '#FFFFFF' : '#23302A' }}">{{ $haut['niveau'] }}</text>
                        <text x="36" y="56" text-anchor="middle" font-size="13" font-weight="800" fill="{{ $haut['niveau'] >= 4 ? '#FFFFFF' : '#23302A' }}">{{ $bas['niveau'] }}</text>
                    @else
                        <text x="36" y="50" text-anchor="middle" font-size="20" font-weight="800" fill="{{ $haut['niveau'] >= 4 ? '#FFFFFF' : '#23302A' }}">{{ $haut['niveau'] }}</text>
                    @endif
                </svg>
                <div class="flex flex-col gap-1">
                    @foreach ($bra['niveaux'] as $i => $n)
                        <span class="{{ $i === 0 ? 'text-xl' : 'text-base' }} font-extrabold" data-niveau="{{ $n['niveau'] }}">{{ $n['niveau'] }} · {{ $noms[$n['niveau']] }}@if ($n['ou'] !== null) <span class="text-sm font-semibold text-gris">{{ $n['ou'] }}</span>@endif</span>
                    @endforeach
                    @if ($bra['demain'] !== null)
                        <span class="text-sm text-texte-2">Demain : {{ $bra['demain'] }} · {{ $noms[$bra['demain']] }} au plus</span>
                    @endif
                </div>
            </div>
            <ul class="flex flex-wrap gap-1.5 text-xs font-bold" aria-label="Échelle du risque d'avalanche">
                @foreach ($noms as $k => $nom)
                    <li class="rounded-md px-2 py-0.5 {{ $echelle[$k][1] }} {{ $k === $haut['niveau'] ? 'outline-2 outline-encre' : '' }}" @if ($k === $haut['niveau']) aria-current="true" @endif>{{ $k }} {{ $nom }}</li>
                @endforeach
            </ul>
            @if ($bra['resume'] !== '')
                <p class="text-sm text-texte-2" data-bra-resume>{{ $bra['resume'] }}</p>
            @endif
        @endif
        <p class="text-sm text-texte-2">Ne remplace pas la lecture du bulletin complet avant une sortie hors piste ou en randonnée.</p>
        <a href="{{ $lienAvalanche }}" target="_blank" rel="noopener noreferrer" class="{{ $lien }} self-start">Bulletin complet de Météo-France ↗</a>
    </section>

    <section class="flex flex-[1_1_380px] flex-col gap-3.5 rounded-2xl border border-bord bg-white p-6" aria-labelledby="enneigement-massif" data-enneigement-massif>
        <h2 id="enneigement-massif" class="text-lg font-extrabold">Enneigement · {{ $massif }}</h2>
        @php($en = $bra['enneigement'] ?? null)
        @if ($bra === null)
            <p role="status" class="font-bold">Pas de bulletin d'avalanche en ce moment</p>
        @elseif ($en === null)
            <p role="status" class="font-bold" data-enneigement-indisponible>Donnée indisponible</p>
        @else
            @php($maxNeige = max(1, ...array_map(fn ($a) => max((int) $a['nord'], (int) $a['sud']), $en['altitudes'] ?: [['nord' => 0, 'sud' => 0]])))
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs font-bold text-gris"><th scope="col" class="w-20 pb-1">Altitude</th><th scope="col" class="pb-1">Versant nord</th><th scope="col" class="pb-1">Versant sud</th></tr></thead>
                <tbody>
                    @foreach ($en['altitudes'] as $a)
                        <tr data-altitude="{{ $a['altitude'] }}">
                            <th scope="row" class="py-1 text-left font-bold text-texte-2">{{ number_format($a['altitude'], 0, ',', ' ') }} m</th>
                            @foreach (['nord' => '#1F4D3A', 'sud' => '#A8D3E6'] as $versant => $couleur)
                                <td class="py-1"><span class="flex items-center gap-1.5">@if ($a[$versant] !== null && $a[$versant] > 0)<svg width="{{ max(4, (int) round($a[$versant] / $maxNeige * 140)) }}" height="10" aria-hidden="true"><rect width="100%" height="10" rx="5" fill="{{ $couleur }}"/></svg>@endif<b>{{ $a[$versant] === null ? '–' : $a[$versant].' cm' }}</b></span></td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <p class="text-sm"><b>Limite d'enneigement :</b> {{ $en['limite_nord'] === null ? '–' : number_format($en['limite_nord'], 0, ',', ' ').' m' }} au nord · {{ $en['limite_sud'] === null ? '–' : number_format($en['limite_sud'], 0, ',', ' ').' m' }} au sud</p>
        @endif
        <p class="text-xs text-gris">Source : bulletin d'estimation du risque d'avalanche de Météo-France, massif {{ $massif }}.</p>
    </section>
</div>

{{-- US-707 : bulletin de M. Caplain, texte seul, toujours échappé (N2). --}}
<section class="flex flex-col gap-3 rounded-2xl border border-bord border-l-[6px] border-l-accent bg-white p-6" aria-labelledby="caplain" data-caplain>
    <div class="flex flex-wrap items-baseline justify-between gap-2.5">
        <h2 id="caplain" class="text-lg font-extrabold">Le bulletin de M. Caplain{{ $bulletin !== null && $bulletin['region'] !== '' ? ' · '.$bulletin['region'] : '' }}</h2>
        @if ($bulletin !== null && $bulletin['emis'] !== '')
            <span class="text-xs text-gris" data-emis>Émis le {{ $bulletin['emis'] }}</span>
        @endif
    </div>
    @if ($bulletin === null)
        <p role="status" class="font-bold" data-caplain-indisponible>Bulletin indisponible</p>
    @else
        @foreach ($bulletin['sections'] as $s)
            <div class="flex flex-col gap-1.5" data-section>
                <h3 class="text-sm font-extrabold text-sapin">{{ $s['titre'] }}</h3>
                @if ($s['texte'] !== '')
                    <p class="leading-relaxed">{{ $s['texte'] }}</p>
                @endif
                @if ($s['donnees'] !== [])
                    <ul class="grid grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-x-4 gap-y-1 rounded-[10px] bg-fond px-3.5 py-3 text-sm">
                        @foreach ($s['donnees'] as $d)<li>{{ mb_strtoupper(mb_substr($d, 0, 1)).mb_substr($d, 1) }}</li>@endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    @endif
    <p class="text-sm italic text-texte-2" data-credit-caplain>Bulletin rédigé par M. Caplain, que nous remercions pour ce travail de passionné. Source : <a href="{{ \App\Domain\Meteo\BulletinCaplain::URL }}" target="_blank" rel="noopener noreferrer" class="font-bold text-sapin">mto38.free.fr</a></p>
</section>
@endsection

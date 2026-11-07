@extends('layouts.app')
@section('titre', 'Statistiques')
@section('large', '1')
@section('sans-titre', '1')
@php
    \Carbon\Carbon::setLocale('fr');
    $date = fn (\DateTimeImmutable $d) => \Carbon\Carbon::instance($d)->translatedFormat('j F Y');
    $nomsMois = ['01' => 'Janv.', '02' => 'Févr.', '03' => 'Mars', '04' => 'Avr.', '05' => 'Mai', '06' => 'Juin', '07' => 'Juil.', '08' => 'Août', '09' => 'Sept.', '10' => 'Oct.', '11' => 'Nov.', '12' => 'Déc.'];
    // Couleurs du calendrier, par criticité croissante (décision du 7 nov. 2026).
    $couleurs = ['ouverte' => '#A8D3E6', 'exclusive' => '#F2D06B', 'attribuee' => '#23302A'];
    $libelles = ['ouverte' => 'Ouvertes', 'exclusive' => '🔒 Exclusives', 'attribuee' => 'Attribuées'];
    $max = max(1, ...array_column($p['mois'], 'jours'));
    $n = count($p['mois']);
    $pas = $n > 6 ? 56 : 110;
    $hauteur = 200;
    $moyenne = $p['nuits'] === 0 ? '0' : number_format($p['nuitees_personnes'] / $p['nuits'], 1, ',', ' ');
    $choix = 'h-11 rounded-lg border border-bord-fort bg-white px-3 text-sm font-semibold';
@endphp
@section('contenu')
<div class="flex flex-wrap items-center gap-3" data-statistiques>
    <div class="mr-auto flex flex-col gap-1">
        <h1 class="text-2xl font-extrabold">Occupation de l'appartement</h1>
        <p class="text-sm text-gris">Chiffres pour l'appartement uniquement, jamais par famille ni par personne.</p>
        <p class="text-sm font-bold text-sapin" data-periode>
            @if ($vue === 'saison')
                Saison {{ $choisi }} · du {{ $date($bornes['debut']) }} au {{ $date($bornes['fin']->modify('+1 day')) }}
                {{ $bornes['approchee'] ? '(dates approchées)' : '(dates de la station)' }}
            @else
                Année civile {{ $choisi }} · du 1er janvier au 31 décembre
            @endif
        </p>
    </div>
    <nav aria-label="Période" class="flex rounded-[10px] bg-bord p-[3px] text-sm">
        <a href="{{ route('statistiques') }}" @if ($vue === 'saison') aria-current="page" @endif class="flex min-h-11 items-center rounded-lg px-3.5 {{ $vue === 'saison' ? 'bg-white font-bold' : 'font-semibold text-texte-2' }}">Saison station</a>
        <a href="{{ route('statistiques', ['vue' => 'annee']) }}" @if ($vue === 'annee') aria-current="page" @endif class="flex min-h-11 items-center rounded-lg px-3.5 {{ $vue === 'annee' ? 'bg-white font-bold' : 'font-semibold text-texte-2' }}">Année civile</a>
    </nav>
    <form method="GET" action="{{ route('statistiques') }}" class="flex items-center gap-2">
        @if ($vue === 'annee')
            <input type="hidden" name="vue" value="annee">
            <label for="choix" class="sr-only">Année</label>
            <select id="choix" name="annee" class="{{ $choix }}">@foreach ($choixAnnees as $a)<option value="{{ $a }}" @selected((string) $a === $choisi)>Année {{ $a }}</option>@endforeach</select>
        @else
            <label for="choix" class="sr-only">Saison</label>
            <select id="choix" name="saison" class="{{ $choix }}">@foreach ($choixSaisons as $s)<option value="{{ $s }}" @selected($s === $choisi)>Saison {{ $s }}</option>@endforeach</select>
        @endif
        <button type="submit" class="h-11 rounded-lg bg-sapin px-3 text-sm font-bold text-white">Afficher</button>
    </form>
</div>

<div class="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
    @foreach ([
        ['Nuits occupées', \App\Domain\Statistiques\Statistiques::taux($p['nuits'], $p['jours']).' %', $p['nuits'].' nuits sur '.$p['jours'], 'nuits'],
        ['Journées occupées', \App\Domain\Statistiques\Statistiques::taux($p['journees'], $p['jours']).' %', $p['journees'].' journées sur '.$p['jours'], 'journees'],
        ['Nuitées-personnes', (string) $p['nuitees_personnes'], $moyenne.' personnes par nuit occupée', 'personnes'],
        ['Samedis soirs occupés', $p['samedis_occupes'].' / '.$p['samedis'], $vue === 'saison' ? 'week-ends de la saison' : 'week-ends de l\'année', 'samedis'],
    ] as [$titre, $valeur, $detail, $cle])
        <div class="flex flex-col gap-1.5 rounded-2xl border border-bord bg-white p-4" data-chiffre="{{ $cle }}">
            <span class="text-sm font-semibold text-gris">{{ $titre }}</span>
            <span class="text-3xl font-extrabold tracking-tight">{{ $valeur }}</span>
            <span class="text-sm text-texte-2">{{ $detail }}</span>
        </div>
    @endforeach
</div>

<div class="flex flex-wrap gap-5">
    <section class="flex min-w-0 flex-[999_1_560px] flex-col gap-4 rounded-2xl border border-bord bg-white p-5" aria-labelledby="par-mois">
        <div class="flex flex-wrap justify-between gap-3">
            <h2 id="par-mois" class="text-lg font-extrabold">Nuits occupées par mois</h2>
            <ul class="flex flex-wrap gap-3.5 text-sm text-texte-2" data-legende>
                @foreach (\App\Domain\Statistiques\Statistiques::TYPES as $t)
                    <li class="flex items-center gap-1.5" data-type="{{ $t }}"><svg width="12" height="12" aria-hidden="true"><rect width="12" height="12" rx="3" fill="{{ $couleurs[$t] }}"/></svg>{{ $libelles[$t] }} · {{ $p['par_type'][$t] }} nuits</li>
                @endforeach
                <li class="flex items-center gap-1.5" data-type="libres"><svg width="12" height="12" aria-hidden="true"><rect width="12" height="12" rx="3" fill="#DCE5DA"/></svg>Non occupées · {{ $p['jours'] - $p['nuits'] }} nuits</li>
            </ul>
        </div>
        {{-- SVG : la CSP interdit les styles en ligne ; chaque barre porte sa valeur écrite (US-702-P2). --}}
        <div class="overflow-x-auto">
            <svg role="img" aria-labelledby="par-mois" viewBox="0 0 {{ $n * $pas }} {{ $hauteur + 50 }}" width="{{ $n * $pas }}" height="{{ $hauteur + 50 }}" class="mx-auto max-w-full" data-graphique>
                @foreach ($p['mois'] as $i => $m)
                    @php($x = $i * $pas + $pas * 0.15)
                    @php($l = $pas * 0.7)
                    @php($y = $hauteur + 20)
                    @foreach ([...\App\Domain\Statistiques\Statistiques::TYPES, 'libres'] as $t)
                        @php($h = round($m[$t] / $max * $hauteur, 1))
                        @if ($h > 0)
                            @php($y -= $h)
                            <rect x="{{ $x }}" y="{{ $y }}" width="{{ $l }}" height="{{ $h }}" fill="{{ $couleurs[$t] ?? '#DCE5DA' }}"><title>{{ $libelles[$t] ?? 'Non occupées' }} : {{ $m[$t] }}</title></rect>
                        @endif
                    @endforeach
                    {{-- US-702-P4 : occupées / nuits du mois dans la période. --}}
                    <text x="{{ $x + $l / 2 }}" y="{{ $y - 6 }}" text-anchor="middle" font-size="13" fill="#23302A" data-valeur="{{ $m['mois'] }}"><tspan font-weight="800">{{ $m['total'] }}</tspan><tspan fill="#56664F"> / {{ $m['jours'] }}</tspan></text>
                    <text x="{{ $x + $l / 2 }}" y="{{ $hauteur + 40 }}" text-anchor="middle" font-size="13" font-weight="600" fill="#56664F">{{ $nomsMois[substr($m['mois'], 5, 2)] }}</text>
                @endforeach
                <line x1="0" x2="{{ $n * $pas }}" y1="{{ $hauteur + 20 }}" y2="{{ $hauteur + 20 }}" stroke="#C2CFBF"/>
            </svg>
        </div>
        <table class="sr-only">
            <caption>Nuits occupées par mois et par type</caption>
            <thead><tr><th scope="col">Mois</th>@foreach (\App\Domain\Statistiques\Statistiques::TYPES as $t)<th scope="col">{{ $libelles[$t] }}</th>@endforeach<th scope="col">Occupées</th><th scope="col">Non occupées</th></tr></thead>
            <tbody>@foreach ($p['mois'] as $m)<tr data-mois="{{ $m['mois'] }}"><th scope="row">{{ $m['mois'] }}</th><td>{{ $m['ouverte'] }}</td><td>{{ $m['exclusive'] }}</td><td>{{ $m['attribuee'] }}</td><td>{{ $m['total'] }}</td><td>{{ $m['libres'] }}</td></tr>@endforeach</tbody>
        </table>
    </section>

    <section class="flex flex-[1_1_320px] flex-col gap-4 rounded-2xl border border-bord bg-white p-5" aria-labelledby="par-saison">
        <h2 id="par-saison" class="text-lg font-extrabold">Taux d'occupation des nuits, saison par saison</h2>
        @forelse ($saisons as $s)
            <div class="grid grid-cols-[92px_minmax(0,1fr)_44px] items-center gap-x-2.5 gap-y-1 text-sm" data-saison="{{ $s['saison'] }}">
                <span class="font-semibold text-texte-2">{{ $s['saison'] }}</span>
                <svg viewBox="0 0 100 12" preserveAspectRatio="none" class="h-3 w-full" aria-hidden="true"><rect width="100" height="12" rx="6" fill="#E6EFE8"/><rect width="{{ $s['taux'] }}" height="12" rx="6" fill="#1F4D3A"/></svg>
                <span class="text-right font-extrabold">{{ $s['taux'] }} %</span>
                <span class="col-start-2 col-end-4 text-xs text-gris">{{ $s['source'] }}{{ $s['approchee'] ? ' · dates approchées' : '' }}</span>
            </div>
        @empty
            <p class="text-sm text-gris">Aucune saison à comparer pour l'instant.</p>
        @endforelse
        <p class="text-xs text-gris">Saisons sans dates de station saisies : du 1er décembre au 30 avril (dates approchées).</p>
    </section>
</div>
@endsection

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
    $max = max(1, ...array_column($barres, 'jours'));
    $n = count($barres);
    $pas = $n > 6 ? 56 : 110;
    $hauteur = 200;
    $moyenne = $p['nuits'] === 0 ? '0' : number_format($p['nuitees_personnes'] / $p['nuits'], 1, ',', ' ');
    $choix = 'h-11 rounded-lg border border-bord-fort bg-white px-3 text-sm font-semibold';
@endphp
@section('contenu')
<div class="flex flex-wrap items-center gap-3" data-statistiques>
    <div class="mr-auto flex flex-col gap-1">
        <h1 class="text-2xl font-extrabold">Occupation de l'appartement</h1>
        <p class="text-sm text-gris">{{ $parLignee === null ? 'Chiffres pour l\'appartement uniquement, jamais par famille ni par personne.' : 'Chiffres pour l\'appartement, et par lignée et par membre pour vous seul.' }}</p>
        <p class="text-sm font-bold text-sapin" data-periode>
            @if ($vue === 'tout')
                Depuis le début · du {{ $date($bornes['debut']) }} (première réservation reprise) à aujourd'hui
            @elseif ($vue === 'saison')
                Saison {{ $choisi }} · du {{ $date($bornes['debut']) }} au {{ $date($bornes['fin']->modify('+1 day')) }}
                {{ $bornes['approchee'] ? '(dates approchées)' : '(dates de la station)' }}
            @else
                Année civile {{ $choisi }} · du 1er janvier au 31 décembre
            @endif
        </p>
    </div>
    <nav aria-label="Période" class="flex max-w-full overflow-x-auto rounded-[10px] bg-bord p-[3px] text-sm">
        <a href="{{ route('statistiques') }}" @if ($vue === 'saison') aria-current="page" @endif class="flex min-h-11 items-center whitespace-nowrap rounded-lg px-2.5 sm:px-3.5 {{ $vue === 'saison' ? 'bg-white font-bold' : 'font-semibold text-texte-2' }}">Saison station</a>
        <a href="{{ route('statistiques', ['vue' => 'annee']) }}" @if ($vue === 'annee') aria-current="page" @endif class="flex min-h-11 items-center whitespace-nowrap rounded-lg px-2.5 sm:px-3.5 {{ $vue === 'annee' ? 'bg-white font-bold' : 'font-semibold text-texte-2' }}">Année civile</a>
        <a href="{{ route('statistiques', ['vue' => 'tout']) }}" @if ($vue === 'tout') aria-current="page" @endif class="flex min-h-11 items-center whitespace-nowrap rounded-lg px-2.5 sm:px-3.5 {{ $vue === 'tout' ? 'bg-white font-bold' : 'font-semibold text-texte-2' }}">Depuis le début</a>
    </nav>
    @unless ($vue === 'tout')
    <form method="GET" action="{{ route('statistiques') }}" class="flex items-center gap-2">
        @if ($vue === 'annee')
            <input type="hidden" name="vue" value="annee">
            <label for="choix" class="sr-only">Année</label>
            <select id="choix" name="annee" class="{{ $choix }}" data-envoi-auto>@foreach ($choixAnnees as $a)<option value="{{ $a }}" @selected((string) $a === $choisi)>Année {{ $a }}</option>@endforeach</select>
        @else
            <label for="choix" class="sr-only">Saison</label>
            <select id="choix" name="saison" class="{{ $choix }}" data-envoi-auto>@foreach ($choixSaisons as $s)<option value="{{ $s }}" @selected($s === $choisi)>Saison {{ $s }}</option>@endforeach</select>
        @endif
        {{-- Le choix dans la liste suffit (décision du 7 nov. 2026) ; bouton gardé seulement sans JavaScript. --}}
        <noscript><button type="submit" class="h-11 rounded-lg bg-sapin px-3 text-sm font-bold text-white">Afficher</button></noscript>
    </form>
    @endunless
</div>

<div class="grid grid-cols-2 gap-3.5 lg:grid-cols-4">
    @foreach ([
        ['Nuits occupées', \App\Domain\Statistiques\Statistiques::taux($p['nuits'], $p['jours']).' %', $p['nuits'].' nuits sur '.$p['jours'], 'nuits'],
        ['Journées occupées', \App\Domain\Statistiques\Statistiques::taux($p['journees'], $p['jours']).' %', $p['journees'].' journées sur '.$p['jours'], 'journees'],
        ['Nuitées-personnes', (string) $p['nuitees_personnes'], $moyenne.' personnes par nuit occupée', 'personnes'],
        ['Samedis soirs occupés', $p['samedis_occupes'].' / '.$p['samedis'], match ($vue) { 'saison' => 'week-ends de la saison', 'tout' => 'week-ends depuis le début', default => 'week-ends de l\'année' }, 'samedis'],
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
            <h2 id="par-mois" class="text-lg font-extrabold">{{ $vue === 'tout' ? 'Nuits occupées par année' : 'Nuits occupées par mois' }}</h2>
            <ul class="flex flex-wrap gap-3.5 text-sm text-texte-2" data-legende>
                @foreach (\App\Domain\Statistiques\Statistiques::TYPES as $t)
                    <li class="flex items-center gap-1.5" data-type="{{ $t }}"><svg width="12" height="12" aria-hidden="true"><rect width="12" height="12" rx="3" fill="{{ $couleurs[$t] }}"/></svg>{{ $libelles[$t] }} · {{ $p['par_type'][$t] }} nuits</li>
                @endforeach
                <li class="flex items-center gap-1.5" data-type="libres"><svg width="12" height="12" aria-hidden="true"><rect width="12" height="12" rx="3" fill="#DCE5DA"/></svg>Non occupées · {{ $p['jours'] - $p['nuits'] }} nuits</li>
            </ul>
        </div>
        {{-- SVG : la CSP interdit les styles en ligne ; chaque barre porte sa valeur écrite (US-702-P2). --}}
        <div class="overflow-x-auto">
            <svg role="img" aria-labelledby="par-mois" viewBox="0 0 {{ $n * $pas }} {{ $hauteur + 50 }}" width="{{ $n * $pas }}" height="{{ $hauteur + 50 }}" class="mx-auto max-w-none" data-graphique>
                @foreach ($barres as $i => $m)
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
                    <text x="{{ $x + $l / 2 }}" y="{{ $hauteur + 40 }}" text-anchor="middle" font-size="13" font-weight="600" fill="#56664F">{{ $vue === 'tout' ? $m['mois'] : $nomsMois[substr($m['mois'], 5, 2)] }}</text>
                @endforeach
                <line x1="0" x2="{{ $n * $pas }}" y1="{{ $hauteur + 20 }}" y2="{{ $hauteur + 20 }}" stroke="#C2CFBF"/>
            </svg>
        </div>
        @if ($vue === 'tout' && $barres !== [])
            <p class="text-xs text-gris" data-note-tout>{{ $barres[0]['mois'] }} commence à la première réservation reprise ({{ \Carbon\Carbon::instance($bornes['debut'])->translatedFormat('j F') }}) ; {{ $barres[count($barres) - 1]['mois'] }} s'arrête à aujourd'hui ({{ \Carbon\Carbon::instance($bornes['fin'])->translatedFormat('j F') }}). Les nuits non occupées sont comptées sur ces mêmes périodes.</p>
        @endif
        <table class="sr-only">
            <caption>{{ $vue === 'tout' ? 'Nuits occupées par année et par type' : 'Nuits occupées par mois et par type' }}</caption>
            <thead><tr><th scope="col">{{ $vue === 'tout' ? 'Année' : 'Mois' }}</th>@foreach (\App\Domain\Statistiques\Statistiques::TYPES as $t)<th scope="col">{{ $libelles[$t] }}</th>@endforeach<th scope="col">Occupées</th><th scope="col">Non occupées</th></tr></thead>
            <tbody>@foreach ($barres as $m)<tr data-mois="{{ $m['mois'] }}"><th scope="row">{{ $m['mois'] }}</th><td>{{ $m['ouverte'] }}</td><td>{{ $m['exclusive'] }}</td><td>{{ $m['attribuee'] }}</td><td>{{ $m['total'] }}</td><td>{{ $m['libres'] }}</td></tr>@endforeach</tbody>
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

@if ($parLignee !== null)
    {{-- US-711 (ADR 019) : seulement pour les comptes listés dans STATISTIQUES_LIGNEES. --}}
    @php($maxLignee = max(1, ...array_column($parLignee, 'total')))
    @php($symboles = [['●', 'text-[#5E9E52]'], ['■', 'text-[#D9879C]'], ['▲', 'text-[#C9A227]']])
    @php($periodeTexte = match ($vue) { 'tout' => 'depuis le début', 'annee' => 'année '.$choisi, default => 'saison '.$choisi })
    <section class="flex flex-col gap-4 rounded-2xl border-2 border-sapin bg-white p-5" aria-labelledby="par-lignee" data-par-lignee>
        <div class="flex flex-wrap items-center justify-between gap-2.5">
            <h2 id="par-lignee" class="text-lg font-extrabold">Par lignée</h2>
            <span class="rounded-full bg-sapin-clair px-2.5 py-1 text-xs font-bold text-sapin">🔒 Visible par vous seul</span>
        </div>
        <p class="text-sm text-gris">Même période que plus haut ({{ $periodeTexte }}). Une réservation compte pour la lignée du membre qui l'a faite ; une période de vacances, pour la lignée attribuée.</p>
        <ul class="flex flex-wrap gap-3.5 text-xs text-texte-2">
            @foreach (\App\Domain\Statistiques\Statistiques::TYPES as $t)
                <li class="flex items-center gap-1.5"><svg width="12" height="12" aria-hidden="true"><rect width="12" height="12" rx="3" fill="{{ $couleurs[$t] }}"/></svg>{{ $libelles[$t] }}</li>
            @endforeach
        </ul>
        @foreach ($parLignee as $l)
            @php($symbole = $l['rang'] >= 0 ? $symboles[$l['rang'] % 3] : ['○', 'text-gris'])
            <div class="grid grid-cols-[minmax(0,1fr)_56px] items-center gap-x-3.5 gap-y-1.5 sm:grid-cols-[170px_minmax(0,1fr)_64px]" data-lignee="{{ $l['nom'] }}">
                <span class="flex items-center gap-2 text-sm font-bold"><span aria-hidden="true" class="{{ $symbole[1] }}">{{ $symbole[0] }}</span>{{ $l['nom'] }}</span>
                <svg viewBox="0 0 100 18" preserveAspectRatio="none" class="col-span-2 row-start-2 h-[18px] w-full sm:col-span-1 sm:col-start-2 sm:row-start-1" aria-hidden="true">
                    <rect width="100" height="18" rx="6" fill="#E6EFE8"/>
                    @php($x = 0)
                    @foreach (\App\Domain\Statistiques\Statistiques::TYPES as $t)
                        @php($w = round($l[$t] / $maxLignee * 100, 2))
                        @if ($w > 0)<rect x="{{ $x }}" width="{{ $w }}" height="18" fill="{{ $couleurs[$t] }}"/>@endif
                        @php($x += $w)
                    @endforeach
                </svg>
                <span class="text-right text-sm font-extrabold sm:col-start-3 sm:row-start-1">{{ $l['total'] }} n.</span>
                <span class="col-span-2 text-xs text-gris sm:col-start-2 sm:col-end-4">{{ \App\Domain\Statistiques\Statistiques::taux($l['total'], $p['nuits']) }} % des nuits occupées · {{ $l['ouverte'] }} ouvertes, {{ $l['exclusive'] }} exclusives, {{ $l['attribuee'] }} attribuées · {{ $l['personnes'] }} nuitées-personnes · {{ $l['reservations'] }} réservation{{ $l['reservations'] > 1 ? 's' : '' }}</span>
            </div>
        @endforeach
        <p class="text-xs text-gris">Les membres sans lignée sont comptés dans « Sans lignée » : leur lignée se choisit sur leur fiche, dans Admin → Utilisateurs.</p>
    </section>
@endif

@if ($parMembre !== null)
    {{-- US-712 (ADR 019) : mêmes comptes que l'encadré par lignée ; jamais l'adresse e-mail. --}}
    @php($maxMembre = max(1, ...array_column($parMembre, 'total')))
    @php($symboles = [['●', 'text-[#5E9E52]'], ['■', 'text-[#D9879C]'], ['▲', 'text-[#C9A227]']])
    <section class="flex flex-col gap-3.5 rounded-2xl border-2 border-sapin bg-white p-5" aria-labelledby="par-membre" data-par-membre>
        <div class="flex flex-wrap items-center justify-between gap-2.5">
            <h2 id="par-membre" class="text-lg font-extrabold">Par membre</h2>
            <span class="rounded-full bg-sapin-clair px-2.5 py-1 text-xs font-bold text-sapin">🔒 Visible par vous seul</span>
        </div>
        <p class="text-sm text-gris">Même période. Nuits réservées par chaque membre, ouvertes et exclusives ; les vacances attribuées ne comptent que pour la lignée, plus haut. Du plus grand nombre de nuits au plus petit.</p>
        @if ($parMembre === [])
            <p class="text-sm text-gris">Aucune réservation sur la période.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full min-w-[640px] border-collapse text-sm">
                    <thead><tr class="text-xs text-gris">
                        <th scope="col" class="px-2 py-1.5 text-left">Membre</th><th scope="col" class="px-2 py-1.5 text-left">Lignée</th><th scope="col" class="px-2 py-1.5"><span class="sr-only">Répartition</span></th>
                        <th scope="col" class="px-2 py-1.5 text-right">Nuits</th><th scope="col" class="px-2 py-1.5 text-right">Ouv. · Excl.</th><th scope="col" class="px-2 py-1.5 text-right">Nuitées-pers.</th><th scope="col" class="px-2 py-1.5 text-right">Réservations</th>
                    </tr></thead>
                    <tbody>
                        @foreach ($parMembre as $m)
                            @php($symbole = $m['rang'] >= 0 ? $symboles[$m['rang'] % 3] : ['○', 'text-gris'])
                            <tr class="border-t border-bord" data-membre>
                                <th scope="row" class="whitespace-nowrap px-2 py-2.5 text-left font-bold">{{ $m['nom'] }}</th>
                                <td class="whitespace-nowrap px-2 py-2.5">@if ($m['lignee'] !== null)<span aria-hidden="true" class="{{ $symbole[1] }}">{{ $symbole[0] }}</span> {{ $m['lignee'] }}@else<span class="text-gris">Sans lignée</span>@endif</td>
                                <td class="min-w-[140px] px-2 py-2.5">
                                    <svg viewBox="0 0 100 12" preserveAspectRatio="none" class="h-3 w-full" aria-hidden="true">
                                        <rect width="100" height="12" rx="4" fill="#E6EFE8"/>
                                        @php($wo = round($m['ouverte'] / $maxMembre * 100, 2))
                                        @php($we = round($m['exclusive'] / $maxMembre * 100, 2))
                                        @if ($wo > 0)<rect width="{{ $wo }}" height="12" fill="{{ $couleurs['ouverte'] }}"/>@endif
                                        @if ($we > 0)<rect x="{{ $wo }}" width="{{ $we }}" height="12" fill="{{ $couleurs['exclusive'] }}"/>@endif
                                    </svg>
                                </td>
                                <td class="px-2 py-2.5 text-right font-extrabold">{{ $m['total'] }}</td>
                                <td class="px-2 py-2.5 text-right">{{ $m['ouverte'] }} · {{ $m['exclusive'] }}</td>
                                <td class="px-2 py-2.5 text-right">{{ $m['personnes'] }}</td>
                                <td class="px-2 py-2.5 text-right">{{ $m['reservations'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
        <p class="text-xs text-gris">Comptes supprimés : prénom seul.</p>
    </section>
@endif
@endsection

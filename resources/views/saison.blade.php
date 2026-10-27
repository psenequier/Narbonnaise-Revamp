@extends('layouts.app')
@section('titre', 'Saison '.$saison)
@section('contenu')
<div class="flex flex-wrap items-center gap-2">
    <a href="{{ route('saison', $precedente) }}" class="flex min-h-11 items-center rounded-lg border border-bord bg-white px-3">‹ {{ $precedente }}</a>
    <a href="{{ route('saison', $suivante) }}" class="flex min-h-11 items-center rounded-lg border border-bord bg-white px-3">{{ $suivante }} ›</a>
    <a href="{{ route('calendrier') }}" class="ml-auto flex min-h-11 items-center rounded-lg border border-bord bg-white px-3">Vue mois</a>
</div>

@if ($sansDonnees)
    <p role="note" class="rounded-lg border border-bord bg-white p-4">
        <strong>Pas encore ouvert.</strong> Aucune donnée pour cette saison : elle n'est pas encore ouverte à la réservation,
        ou elle est antérieure à l'historique du site.
    </p>
@else
    @if ($ouverture || $fermeture)
        <p class="text-sm">
            @if ($ouverture)Ouverture de la station : <strong>{{ \Illuminate\Support\Carbon::parse($ouverture)->format('d/m/Y') }}</strong>.@endif
            @if ($fermeture)Fermeture : <strong>{{ \Illuminate\Support\Carbon::parse($fermeture)->format('d/m/Y') }}</strong>.@endif
        </p>
    @endif
    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
    @foreach ($mois as $cle => $jours)
        <section class="rounded-2xl border border-bord bg-white p-3">
            <h2 class="mb-2 font-extrabold first-letter:uppercase">{{ \Illuminate\Support\Carbon::parse($cle.'-01')->locale('fr')->translatedFormat('F Y') }}</h2>
            <ol class="flex flex-col gap-1">
            @foreach ($jours as $jour)
                @php($date = $jour->format('Y-m-d'))
                <li>
                    <a href="{{ route('jour', $date) }}" class="grid grid-cols-[3.5rem_1fr_1fr] items-center gap-1 rounded-md {{ in_array($jour->format('N'), ['6', '7']) ? 'bg-fond' : '' }}" data-date="{{ $date }}">
                        <span class="text-xs font-bold">
                            {{ ['L', 'M', 'M', 'J', 'V', 'S', 'D'][(int) $jour->format('N') - 1] }} {{ $jour->format('j') }}
                            @if ($date === $ouverture)<span class="block font-normal text-sapin" data-station="ouverture">Ouverture</span>@endif
                            @if ($date === $fermeture)<span class="block font-normal text-sapin" data-station="fermeture">Fermeture</span>@endif
                        </span>
                        @include('partiels.creneau', ['situation' => $situations[$date.'-J']])
                        @include('partiels.creneau', ['situation' => $situations[$date.'-N']])
                    </a>
                </li>
            @endforeach
            </ol>
        </section>
    @endforeach
    </div>
@endif
@endsection

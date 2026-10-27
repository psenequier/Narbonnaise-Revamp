@extends('layouts.app')
@section('titre', ucfirst(\Illuminate\Support\Carbon::instance($jour)->locale('fr')->translatedFormat('l j F Y')))
@section('contenu')
<a href="{{ route('calendrier.mois', $jour->format('Y-m')) }}" class="text-sm underline">‹ Retour au mois</a>

@foreach ($situations as $type => $situation)
    @php($etat = $situation->etat)
    <section class="flex flex-col gap-3 rounded-2xl border border-bord bg-white p-5" data-creneau="{{ $type }}">
        <div class="flex items-center gap-3">
            <h2 class="text-lg font-extrabold">{{ $type === 'J' ? 'Journée' : 'Nuit (jusqu\'au lendemain)' }}</h2>
            @include('partiels.creneau', ['situation' => $situation])
        </div>

        @if ($etat === \App\Domain\Calendrier\EtatCreneau::Attribue)
            {{-- US-204-N1 : seul le nom de la lignée, jamais de nom de personne. --}}
            <p>Attribué à la lignée <strong>{{ $situation->lignee }}</strong>.</p>
        @elseif ($etat === \App\Domain\Calendrier\EtatCreneau::Attente)
            <p class="text-sm">Vacances scolaires : en attente d'attribution par les admins.</p>
        @elseif ($etat === \App\Domain\Calendrier\EtatCreneau::PasOuvert)
            <p class="text-sm">Pas encore ouvert : l'année scolaire s'ouvre quand les vacances de Noël et de février sont attribuées.</p>
        @elseif ($etat === \App\Domain\Calendrier\EtatCreneau::Ferme)
            <p class="text-sm">L'appartement est fermé ce jour-là.</p>
        @elseif ($situation->reservations === [])
            <p class="text-sm">Libre.</p>
        @else
            <ul class="flex flex-col gap-2">
            @foreach ($situation->reservations as $r)
                @php($visible = $r->mode === 'ouverte' || $r->user_id === $moi)
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border border-bord p-3" data-reservation="{{ $r->id }}">
                    <strong>{{ $r->user?->prenom }} {{ $r->user?->nom }}</strong>
                    <span class="text-sm">{{ $r->mode === 'exclusive' ? 'Exclusive' : 'Ouverte' }}</span>
                    @if ($r->nombre_personnes)<span class="text-sm">{{ $r->nombre_personnes }} pers.</span>@endif
                    @if ($visible && $r->invites)<span class="w-full text-sm text-gris">Invités : {{ $r->invites }}</span>@endif
                    @if ($visible && $r->commentaire)<span class="w-full text-sm text-gris">{{ $r->commentaire }}</span>@endif
                    @if ($r->mode === 'ouverte' && ! $situation->contientMembre($moi) && $jour >= $aujourdhui)
                        <a href="{{ route('reservations.formulaire', ['joindre' => $r->id]) }}" class="ml-auto flex min-h-11 items-center rounded-lg bg-sapin px-4 font-bold text-white">Me joindre</a>
                    @endif
                </li>
            @endforeach
            </ul>
        @endif
    </section>
@endforeach

@if ($jour >= $aujourdhui && collect($situations)->contains(fn ($s) => $s->etat->reservable() && ! $s->contientMembre($moi)))
    <a href="{{ route('reservations.formulaire', ['debut' => $jour->format('Y-m-d'), 'fin' => $jour->modify('+1 day')->format('Y-m-d')]) }}" class="self-start rounded-lg bg-sapin px-4 py-3 font-bold text-white">Réserver à partir de ce jour</a>
@endif
@endsection

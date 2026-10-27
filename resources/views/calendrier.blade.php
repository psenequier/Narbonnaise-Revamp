@extends('layouts.app')
@section('titre', 'Calendrier')
@php($jours = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'])
@php($nomMois = \Illuminate\Support\Carbon::instance($premier)->locale('fr')->translatedFormat('F Y'))
@section('contenu')
<div class="flex flex-wrap items-center gap-2">
    <h2 class="mr-auto text-xl font-extrabold first-letter:uppercase" data-mois="{{ $premier->format('Y-m') }}">{{ $nomMois }}</h2>
    <a href="{{ route('calendrier.mois', $precedent) }}" class="flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-bord bg-white px-3" aria-label="Mois précédent">‹</a>
    <a href="{{ route('calendrier') }}" class="flex min-h-11 items-center rounded-lg border border-bord bg-white px-3 font-bold">Aujourd'hui</a>
    <a href="{{ route('calendrier.mois', $suivant) }}" class="flex min-h-11 min-w-11 items-center justify-center rounded-lg border border-bord bg-white px-3" aria-label="Mois suivant">›</a>
    <a href="{{ route('saison') }}" class="flex min-h-11 items-center rounded-lg border border-bord bg-white px-3">Saison</a>
    <a href="{{ route('reservations.formulaire') }}" class="flex min-h-11 items-center rounded-lg bg-sapin px-4 font-bold text-white">Réserver</a>
</div>

@if ($pasOuvert)
    <p role="note" class="rounded-lg border border-bord bg-white p-3 text-sm">
        <strong>Pas encore ouvert :</strong> une année scolaire s'ouvre à la réservation quand les admins ont attribué
        les vacances de Noël et de février. En attendant, ces dates ne peuvent pas être réservées.
    </p>
@endif

{{-- Écran large : grille du mois. --}}
<table class="hidden w-full table-fixed border-separate border-spacing-1 min-[600px]:table">
    <caption class="sr-only">Occupation de l'appartement, {{ $nomMois }}</caption>
    <thead><tr>@foreach ($jours as $j)<th scope="col" class="text-xs font-bold text-gris">{{ $j }}</th>@endforeach</tr></thead>
    <tbody>
    @foreach ($semaines as $semaine)
        <tr>
        @foreach ($semaine as $jour)
            @php($horsMois = $jour->format('m') !== $premier->format('m'))
            <td class="align-top rounded-lg border border-bord bg-white p-1 {{ $horsMois ? 'opacity-50' : '' }} {{ $jour == $aujourdhui ? 'ring-2 ring-sapin' : '' }}">
                <a href="{{ route('jour', $jour->format('Y-m-d')) }}" class="flex flex-col gap-1">
                    <span class="text-xs font-bold">{{ $jour->format('j') }}</span>
                    @include('partiels.creneau', ['situation' => $situations[$jour->format('Y-m-d').'-J']])
                    @include('partiels.creneau', ['situation' => $situations[$jour->format('Y-m-d').'-N']])
                </a>
            </td>
        @endforeach
        </tr>
    @endforeach
    </tbody>
</table>

{{-- Téléphone (moins de 600 px) : liste par semaine, une ligne par jour (US-203-P1). --}}
<div class="flex flex-col gap-4 min-[600px]:hidden" data-vue="liste">
@foreach ($semaines as $semaine)
    <section class="flex flex-col gap-1">
        <h3 class="text-xs font-bold text-gris">Semaine du {{ $semaine[0]->format('d/m') }}</h3>
        @foreach ($semaine as $jour)
            @continue($jour->format('m') !== $premier->format('m'))
            <a href="{{ route('jour', $jour->format('Y-m-d')) }}" class="grid grid-cols-[4.5rem_1fr_1fr] items-center gap-2 rounded-lg border border-bord bg-white p-2 {{ $jour == $aujourdhui ? 'ring-2 ring-sapin' : '' }}">
                <span class="text-sm font-bold">{{ $jours[(int) $jour->format('N') - 1] }} {{ $jour->format('j') }}</span>
                @include('partiels.creneau', ['situation' => $situations[$jour->format('Y-m-d').'-J']])
                @include('partiels.creneau', ['situation' => $situations[$jour->format('Y-m-d').'-N']])
            </a>
        @endforeach
    </section>
@endforeach
</div>

<ul class="flex flex-wrap gap-2 text-xs" aria-label="Légende">
    @foreach (\App\Domain\Calendrier\EtatCreneau::cases() as $etat)
        <li class="creneau creneau-{{ $etat->value }} {{ $etat->motif() }}">{{ $etat->libelle() }}</li>
    @endforeach
</ul>
@endsection

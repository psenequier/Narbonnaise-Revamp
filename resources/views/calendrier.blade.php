@extends('layouts.app')
@section('titre', 'Calendrier')
@section('sans-titre', '1')
@section('large', '1')
@php($nomMois = \Illuminate\Support\Carbon::instance($premier)->locale('fr')->translatedFormat('F Y'))
@php($cleJour = $aujourdhui->format('Y-m-d'))
@section('contenu')
<div class="flex flex-wrap gap-6">
<section aria-label="Calendrier" class="flex min-w-0 flex-[999_1_640px] flex-col gap-4">
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="mr-auto font-display text-[30px] font-bold tracking-tight first-letter:uppercase" data-mois="{{ $premier->format('Y-m') }}">{{ $nomMois }}</h1>
        {{-- À droite, à largeur fixe : les flèches ne bougent pas avec la longueur du nom du mois (clics rapides). --}}
        <div class="flex gap-1.5">
            <a href="{{ route('calendrier.mois', $precedent) }}" class="flex size-11 items-center justify-center rounded-full border border-bord-fort bg-white" aria-label="Mois précédent"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></a>
            <a href="{{ route('calendrier.mois', $suivant) }}" class="flex size-11 items-center justify-center rounded-full border border-bord-fort bg-white" aria-label="Mois suivant"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></a>
            <a href="{{ route('calendrier.mois') }}" class="flex h-11 items-center rounded-full border border-bord-fort bg-white px-3.5 text-sm font-semibold">Aujourd'hui</a>
        </div>
        {{-- Pas de vue saison sur téléphone (demande du 6 nov.). --}}
        <div role="group" aria-label="Vue" class="hidden rounded-full bg-segment p-[3px] text-sm min-[600px]:flex">
            <span aria-current="page" class="flex h-[38px] items-center rounded-full bg-white px-3.5 font-bold">Mois</span>
            <a href="{{ route('saison') }}" class="flex h-[38px] items-center rounded-full px-3.5 font-semibold text-texte-2">Saison déc.–avr.</a>
        </div>
        <a href="{{ route('reservations.formulaire') }}" class="flex h-11 items-center gap-2 rounded-full bg-sapin px-[18px] text-sm font-bold text-white"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>Réserver</a>
    </div>

    @if ($errors->any() && ! is_array(old('creneaux')))
        <div role="alert" class="rounded-lg border border-erreur bg-erreur-fond p-3 text-sm text-erreur">
            @foreach ($errors->all() as $erreur)<p>{{ $erreur }}</p>@endforeach
        </div>
    @endif

    @if ($pasOuvert)
        <p role="note" class="rounded-lg border border-bord bg-white p-3 text-sm">
            <strong>Pas encore ouvert :</strong> une année scolaire s'ouvre à la réservation quand les admins ont attribué
            les vacances de Noël et de février. En attendant, ces dates ne peuvent pas être réservées.
        </p>
    @endif

    @include('partiels.legende')

    @include('partiels.grille-semaines', ['legende' => "Occupation de l'appartement, ".$nomMois, 'dansVue' => fn ($jour) => $jour->format('m') === $premier->format('m')])

    @can('admin')
        <p class="text-sm text-gris" data-aide-attribution><strong>Admin :</strong> cliquez sur un créneau « En attente » puis sur un second pour attribuer ces vacances à une lignée ; cliquez sur un créneau attribué pour changer sa lignée, ses dates, ou retirer l'attribution.</p>
    @endcan
    <p class="text-sm text-gris">J = journée · N = nuit du jour au lendemain. Chaque créneau affiche le réservant, le nombre de personnes et le début du commentaire (tout le détail au survol de la souris) ; un créneau attribué n'affiche que la lignée. Cliquez (ou touchez) un créneau pour le sélectionner, recliquez pour le désélectionner, cliquez sur un second créneau pour choisir la période. Cliquez sur le numéro du jour pour voir le détail.</p>
</section>

</div>

@include('partiels.popup-reservation')
@include('partiels.popup-attribution')
@endsection

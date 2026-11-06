@extends('layouts.app')
@section('titre', 'Calendrier')
@section('sans-titre', '1')
@section('large', '1')
@push('tete')<link rel="stylesheet" href="{{ route('lignees.css') }}">@endpush
@php($jours = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'])
@php($nomMois = \Illuminate\Support\Carbon::instance($premier)->locale('fr')->translatedFormat('F Y'))
@php($cleJour = $aujourdhui->format('Y-m-d'))
@section('contenu')
<div class="flex flex-wrap gap-6">
<section aria-label="Calendrier" class="flex min-w-0 flex-[999_1_640px] flex-col gap-4">
    <div class="flex flex-wrap items-center gap-3">
        <h1 class="font-display text-[30px] font-bold tracking-tight first-letter:uppercase" data-mois="{{ $premier->format('Y-m') }}">{{ $nomMois }}</h1>
        <div class="flex gap-1.5">
            <a href="{{ route('calendrier.mois', $precedent) }}" class="flex size-11 items-center justify-center rounded-full border border-bord-fort bg-white" aria-label="Mois précédent"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M15 6l-6 6 6 6"/></svg></a>
            <a href="{{ route('calendrier.mois', $suivant) }}" class="flex size-11 items-center justify-center rounded-full border border-bord-fort bg-white" aria-label="Mois suivant"><svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg></a>
            <a href="{{ route('calendrier.mois') }}" class="flex h-11 items-center rounded-full border border-bord-fort bg-white px-3.5 text-sm font-semibold">Aujourd'hui</a>
        </div>
        <div role="group" aria-label="Vue" class="ml-auto flex rounded-full bg-segment p-[3px] text-sm">
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

    {{-- Écran large : grille du mois. --}}
    <div class="hidden overflow-x-auto rounded-[14px] border border-bord bg-white min-[600px]:block">
        <table class="w-full min-w-[640px] table-fixed border-collapse">
            <caption class="sr-only">Occupation de l'appartement, {{ $nomMois }}</caption>
            <thead><tr class="border-b border-bord">@foreach ($jours as $j)<th scope="col" class="px-3 py-2.5 text-left text-xs font-bold tracking-wider text-gris uppercase">{{ $j }}</th>@endforeach</tr></thead>
            <tbody>
            @foreach ($semaines as $semaine)
                <tr>
                @foreach ($semaine as $jour)
                    @php($d = $jour->format('Y-m-d'))
                    @php($horsMois = $jour->format('m') !== $premier->format('m'))
                    <td class="h-[120px] border-r border-b border-bord-doux p-2 align-top {{ $horsMois ? 'bg-fond opacity-45' : '' }} {{ $d === $cleJour ? 'shadow-[inset_0_0_0_3px_var(--color-principale)]' : '' }}">
                        <div class="flex flex-col gap-[5px]">
                            <div class="flex items-center justify-between gap-1">
                                    <a href="{{ route('jour', $d) }}" title="Voir le détail du jour" class="self-start rounded px-0.5 text-sm {{ $d === $cleJour ? 'font-extrabold' : 'font-bold' }} {{ $horsMois ? 'text-gris-clair' : 'text-encre' }} hover:underline">{{ $jour->format('j') }}<span class="sr-only"> : détail du {{ $jour->format('d/m') }}</span></a>
                                @if ($d === $cleJour)<span class="rounded-full bg-principale px-2 text-[11px] leading-5 font-bold text-white">Aujourd'hui</span>@endif
                            </div>
                            @include('partiels.case', ['a' => $affichages[$d.'-J']])
                            @include('partiels.case', ['a' => $affichages[$d.'-N']])
                        </div>
                    </td>
                @endforeach
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>

    {{-- Téléphone (moins de 600 px) : liste par semaine, une ligne par jour (US-203-P1). --}}
    <div class="flex flex-col gap-4 min-[600px]:hidden" data-vue="liste">
    @foreach ($semaines as $semaine)
        <section class="flex flex-col gap-1">
            <h3 class="text-xs font-bold text-gris">Semaine du {{ $semaine[0]->format('d/m') }}</h3>
            @foreach ($semaine as $jour)
                @continue($jour->format('m') !== $premier->format('m'))
                <a href="{{ route('jour', $jour->format('Y-m-d')) }}" class="grid grid-cols-[4rem_minmax(0,1fr)_minmax(0,1fr)] items-center gap-2 rounded-xl border border-bord bg-white px-3 py-2.5 {{ $jour == $aujourdhui ? 'ring-2 ring-sapin' : '' }}">
                    <span class="text-sm font-bold">{{ $jours[(int) $jour->format('N') - 1] }} {{ $jour->format('j') }}</span>
                    @foreach (['J', 'N'] as $t)
                        @php($a = $affichages[$jour->format('Y-m-d').'-'.$t])
                        {{-- US-203-P3 : réservant et nombre de personnes ; la lignée seule sur un créneau attribué. --}}
                        <span class="case case-{{ $a->situation->etat->value }} {{ $a->classeLignee() }} min-h-9 cursor-pointer text-xs font-bold">
                            <span class="truncate">{{ $t }} · {{ $a->libelle() !== '' ? $a->libelle() : 'Libre' }}{{ $a->personnes !== null ? ' · '.$a->personnes : '' }}</span>
                        </span>
                    @endforeach
                </a>
            @endforeach
        </section>
    @endforeach
    </div>

    <p class="text-sm text-gris">J = journée · N = nuit du jour au lendemain. Chaque créneau affiche le réservant, le nombre de personnes et le début du commentaire (tout le détail au survol de la souris) ; un créneau attribué n'affiche que la lignée. Cliquez sur un créneau pour le sélectionner, recliquez pour le désélectionner, cliquez sur un second créneau pour choisir la période. Cliquez sur le numéro du jour pour voir le détail.</p>
</section>

@if (isset($affichages[$cleJour.'-J']))
<aside aria-label="Aujourd'hui" class="flex flex-[1_1_300px] flex-col gap-4">
    <section class="flex flex-col gap-3.5 rounded-[14px] border border-bord bg-white p-5">
        <h2 class="text-lg font-extrabold first-letter:uppercase">{{ \Illuminate\Support\Carbon::instance($aujourdhui)->locale('fr')->translatedFormat('l j F') }}</h2>
        @foreach (['J' => 'Journée', 'N' => 'Nuit'] as $t => $nomCreneau)
            @php($a = $affichages[$cleJour.'-'.$t])
            <div class="flex flex-col gap-2">
                <div class="text-xs font-bold tracking-wider text-gris uppercase">{{ $nomCreneau }}{{ $a->personnes !== null ? ' · '.$a->personnes.' personnes' : '' }}</div>
                @forelse ($a->situation->reservations as $r)
                    <div class="flex justify-between text-sm"><span>{{ trim($r->user?->prenom.' '.\App\Domain\Calendrier\AffichageCreneau::initiales((string) $r->user?->nom)) }} · {{ $r->mode === 'exclusive' ? 'exclusive' : 'ouverte' }}</span>@if ($r->nombre_personnes)<span class="text-gris">{{ $r->nombre_personnes }} pers.</span>@endif</div>
                    @if ($r->commentaire)<div class="-mt-1 text-sm text-gris italic">{{ $r->commentaire }}</div>@endif
                @empty
                    <div class="text-sm text-gris">{{ $a->situation->etat === \App\Domain\Calendrier\EtatCreneau::Attribue ? 'Attribué à la lignée '.$a->situation->lignee : $a->situation->etat->libelle() }}</div>
                @endforelse
            </div>
        @endforeach
        <a href="{{ route('jour', $cleJour) }}" class="flex h-11 items-center justify-center rounded-full border-[1.5px] border-sapin text-sm font-bold text-sapin">Voir le détail du jour</a>
    </section>
</aside>
@endif
</div>

@include('partiels.popup-reservation')
@endsection

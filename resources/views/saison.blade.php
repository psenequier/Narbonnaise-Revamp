@extends('layouts.app')
@section('titre', 'Saison '.$saison)
@section('sans-titre', '1')
@section('large', '1')
@push('tete')<link rel="stylesheet" href="{{ route('lignees.css') }}">@endpush
@php($lettres = ['L', 'M', 'M', 'J', 'V', 'S', 'D'])
@section('contenu')
<div hidden data-vue-saison></div>
<div class="flex flex-wrap items-center gap-3">
    <h1 class="mr-auto font-display text-[30px] font-bold tracking-tight">Saison {{ $saison }}</h1>
    <div class="flex gap-1.5">
        <a href="{{ route('saison', $precedente) }}" class="flex h-11 items-center rounded-full border border-bord-fort bg-white px-3 text-sm font-semibold" aria-label="Saison précédente">‹ {{ $precedente }}</a>
        <a href="{{ route('saison', $suivante) }}" class="flex h-11 items-center rounded-full border border-bord-fort bg-white px-3 text-sm font-semibold" aria-label="Saison suivante">{{ $suivante }} ›</a>
    </div>
    <div role="group" aria-label="Vue" class="flex rounded-full bg-segment p-[3px] text-sm">
        <a href="{{ route('calendrier.mois') }}" class="flex h-[38px] items-center rounded-full px-3.5 font-semibold text-texte-2">Mois</a>
        <span aria-current="page" class="flex h-[38px] items-center rounded-full bg-white px-3.5 font-bold">Saison déc.–avr.</span>
    </div>
    <a href="{{ route('reservations.formulaire') }}" class="flex h-11 items-center rounded-full bg-sapin px-[18px] text-sm font-bold text-white">Réserver</a>
</div>

@if ($errors->any() && ! is_array(old('creneaux')))
    <div role="alert" class="rounded-lg border border-erreur bg-erreur-fond p-3 text-sm text-erreur">
        @foreach ($errors->all() as $erreur)<p>{{ $erreur }}</p>@endforeach
    </div>
@endif

@if ($sansDonnees)
    <p role="note" class="rounded-lg border border-bord bg-white p-4">
        <strong>Pas encore ouvert.</strong> Aucune donnée pour cette saison : elle n'est pas encore ouverte à la réservation,
        ou elle est antérieure à l'historique du site.
    </p>
@else
    @include('partiels.legende', ['station' => true])

    {{-- Téléphone (moins de 600 px) : liste par semaine, un appui sur le jour ouvre son détail (US-202-P6, US-203). --}}
    @php($semainesSaison = collect($mois)->flatten(1)->groupBy(fn ($j) => $j->format('o-W')))
    <div class="flex flex-col gap-4 min-[600px]:hidden" data-vue="liste">
    @foreach ($semainesSaison as $semaine)
        <section class="flex flex-col gap-1">
            <h3 class="text-xs font-bold text-gris">Semaine du {{ $semaine->first()->format('d/m') }}</h3>
            @foreach ($semaine as $jour)
                <a href="{{ route('jour', $jour->format('Y-m-d')) }}" class="grid grid-cols-[4rem_minmax(0,1fr)_minmax(0,1fr)] items-center gap-2 rounded-xl border border-bord bg-white px-3 py-2.5">
                    <span class="text-sm font-bold">{{ ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'][(int) $jour->format('N') - 1] }} {{ $jour->format('j') }}</span>
                    @foreach (['J', 'N'] as $t)
                        @php($a = $affichages[$jour->format('Y-m-d').'-'.$t])
                        <span class="case case-{{ $a->situation->etat->value }} {{ $a->classeLignee() }} min-h-9 text-xs font-bold"><span class="truncate">{{ $t }} · {{ $a->libelle() !== '' ? $a->libelle() : 'Libre' }}{{ $a->personnes !== null ? ' · '.$a->personnes : '' }}</span></span>
                    @endforeach
                </a>
            @endforeach
        </section>
    @endforeach
    </div>

    <section aria-label="Saison" class="hidden overflow-x-auto rounded-[14px] border border-bord bg-white p-[18px] min-[600px]:block">
        <div class="flex min-w-[1120px] flex-col gap-3.5">
        @foreach ($mois as $cle => $jours)
            @php($nomMois = \Illuminate\Support\Carbon::parse($cle.'-01')->locale('fr')->translatedFormat('F'))
            <div class="grid grid-cols-[84px_minmax(0,1fr)] items-start gap-2.5">
                <div class="flex flex-col pt-3.5"><span class="text-base font-extrabold first-letter:uppercase">{{ $nomMois }}</span><span class="text-xs text-gris">{{ substr($cle, 0, 4) }}</span></div>
                <div class="grid grid-cols-31 grid-rows-[auto_auto_38px_38px] gap-[3px]">
                    @foreach ($jours as $i => $jour)
                        @php($date = $jour->format('Y-m-d'))
                        @php($weekEnd = in_array($jour->format('N'), ['6', '7'], true))
                        @php($station = $date === $ouverture ? 'ouverture' : ($date === $fermeture ? 'fermeture' : null))
                        <span class="row-start-1 col-start-{{ $i + 1 }} text-center text-sm {{ $weekEnd ? 'font-extrabold text-encre' : 'font-semibold text-gris-clair' }}" aria-hidden="true">{{ $lettres[(int) $jour->format('N') - 1] }}</span>
                        <a href="{{ route('jour', $date) }}" title="Voir le détail du jour" data-date="{{ $date }}" @if ($station) data-station="{{ $station }}" @endif
                           class="row-start-2 col-start-{{ $i + 1 }} rounded text-center text-sm tabular-nums hover:underline {{ $weekEnd ? 'font-extrabold' : 'font-semibold' }} {{ $station ? 'outline-2 outline-station' : '' }}">{{ $jour->format('j') }}<span class="sr-only"> {{ $nomMois }}{{ $station ? ', '.$station.' de la station' : '' }}</span></a>
                    @endforeach

                    @foreach (['J' => 3, 'N' => 4] as $type => $rangee)
                        @php($ligne = array_map(fn ($jour) => $affichages[$jour->format('Y-m-d').'-'.$type], $jours))
                        @foreach (\App\Domain\Calendrier\AffichageCreneau::barres($ligne) as $b)
                            <span aria-hidden="true" class="barre barre-{{ $b['etat'] }} {{ $b['classe'] }} row-start-{{ $rangee }} col-start-{{ $b['debut'] }} col-span-{{ $b['longueur'] }} {{ $b['longueur'] === 1 ? 'items-center px-0 text-center' : '' }}">
                                @php($symbole = $b['lignee'] !== null ? ($symboles[$b['lignee']] ?? '') : '')
                                @if ($b['l1'] !== '' || $symbole !== '')<span class="truncate text-[13px] font-bold">{{ trim($symbole.' '.$b['l1']) }}</span>@endif
                                @if ($b['l2'] !== '')<span class="truncate text-[12px] font-normal {{ $b['longueur'] === 1 ? '' : 'italic' }} opacity-90">{{ $b['l2'] }}</span>@endif
                            </span>
                        @endforeach
                        @foreach ($ligne as $i => $a)
                            <span class="relative z-10 rounded row-start-{{ $rangee }} col-start-{{ $i + 1 }} {{ $a->dispo === 'non' ? 'cursor-not-allowed' : 'cursor-pointer' }}"
                                  data-etat="{{ $a->situation->etat->value }}" data-rang="{{ $a->rang }}" data-cle="{{ $a->situation->cle() }}" data-dispo="{{ $a->dispo }}"
                                  data-label="{{ $a->libelleDate }}" data-qui="{{ $a->qui }}" data-infobulle="{{ $a->infobulleTexte() }}"
                                  @include('partiels.attributs-mienne')
                                  @if ($a->dispo !== 'non') role="button" tabindex="0" @endif
                                  aria-label="{{ $a->libelleDate }} : {{ $a->situation->etat === \App\Domain\Calendrier\EtatCreneau::Attribue ? 'lignée '.$a->situation->lignee : $a->situation->etat->libelle() }}{{ $a->quiComplet !== '' ? ', '.$a->quiComplet : '' }}"></span>
                        @endforeach
                    @endforeach
                </div>
            </div>
        @endforeach
        </div>
    </section>
    <p class="text-sm text-gris">Barre du haut = journée, barre du bas = nuit. Chaque barre porte le réservant, le nombre de personnes et le début du commentaire (initiales et nombre sur un seul créneau) ; tout le détail s'affiche au survol de la souris. Un créneau attribué n'affiche que la lignée. Cliquez sur un créneau pour le sélectionner, recliquez pour le désélectionner, cliquez sur un second créneau pour choisir la période. Cliquez sur le numéro du jour pour voir le détail. Les week-ends sont en gras.</p>
@endif

@include('partiels.popup-reservation')
@endsection

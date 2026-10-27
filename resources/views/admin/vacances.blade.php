@extends('layouts.app')
@section('titre', 'Vacances et attributions')
@php($champ = 'h-11 rounded-lg border border-bord bg-white px-2 font-normal')
@section('contenu')
@if ($errors->any())
    <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
@endif

<nav class="flex flex-wrap gap-2" aria-label="Années scolaires">
    @forelse ($annees as $a)
        <a href="{{ route('admin.vacances', ['annee' => $a->libelle]) }}" class="flex min-h-11 items-center rounded-lg border px-3 {{ $annee?->is($a) ? 'border-sapin bg-sapin-clair font-bold' : 'border-bord bg-white' }}">
            {{ $a->libelle }} {{ $a->ouverte ? '' : '(pas encore ouverte)' }}</a>
    @empty
        <p class="text-sm text-gris">Aucune année connue : la tâche mensuelle lira le calendrier scolaire officiel.</p>
    @endforelse
</nav>

@if ($conflits->isNotEmpty())
<section class="flex flex-col gap-3 rounded-2xl border border-amber-400 bg-amber-50 p-5" aria-labelledby="conflits">
    <h2 id="conflits" class="text-lg font-extrabold">Réservations en conflit avec les vacances</h2>
    @foreach ($conflits as $r)
        <form method="POST" action="{{ route('admin.conflits.arbitrer', $r) }}" class="flex flex-wrap items-end gap-2 rounded-lg bg-white p-3" data-conflit="{{ $r->id }}">
            @csrf
            <span class="mr-auto text-sm"><strong>{{ $r->user?->prenom }} {{ $r->user?->nom }}</strong>,
                du {{ $r->creneaux->first()?->date->format('d/m/Y') }} au {{ $r->creneaux->last()?->date->format('d/m/Y') }}</span>
            <label class="flex flex-col text-xs font-bold">Motif (envoyé au membre)<input name="motif" required minlength="3" maxlength="500" class="{{ $champ }}"></label>
            <button name="decision" value="maintenir" class="h-11 rounded-lg border border-bord px-3 font-bold">Maintenir</button>
            <button name="decision" value="annuler" class="h-11 rounded-lg bg-red-800 px-3 font-bold text-white">Annuler la réservation</button>
        </form>
    @endforeach
</section>
@endif

@foreach ($periodes as $periode)
<section class="flex flex-col gap-4 rounded-2xl border border-bord bg-white p-5" data-periode="{{ $periode->nom }}">
    <div class="flex flex-wrap items-baseline gap-2">
        <h2 class="text-lg font-extrabold">{{ $periode->libelle() }} {{ $annee->libelle }}</h2>
        <span class="text-sm">du {{ $periode->premiere_nuit->format('d/m/Y') }} (nuit) au {{ $periode->dernier_jour->format('d/m/Y') }} (jour)</span>
        @if ($periode->manuelle)<span class="rounded bg-amber-100 px-2 text-xs font-bold">corrigée à la main</span>@endif
    </div>

    <ol class="flex flex-wrap gap-1" aria-label="Créneaux de la période">
        @foreach ($situations[$periode->id] as $cle => $s)
            @continue(($cle === $periode->premiere_nuit->format('Y-m-d').'-J') || ($cle === $periode->dernier_jour->format('Y-m-d').'-N'))
            <li title="{{ $s->creneau->date->format('d/m') }} {{ $s->creneau->type->libelle() }}">
                <span class="sr-only">{{ $s->creneau->date->format('d/m') }}</span>@include('partiels.creneau', ['situation' => $s])
            </li>
        @endforeach
    </ol>

    <h3 class="font-bold">Plages attribuées</h3>
    @forelse ($periode->plages as $plage)
        <form method="POST" action="{{ route('admin.plages.modifier', $plage) }}" class="flex flex-wrap items-end gap-2" data-plage="{{ $plage->id }}">
            @csrf @method('PUT')
            @include('admin._champs-plage', ['valeurs' => $plage])
            <button type="submit" class="h-11 rounded-lg border border-bord px-3 font-bold">Modifier</button>
            <button type="submit" form="retirer-{{ $plage->id }}" class="h-11 rounded-lg border border-red-300 px-3 font-bold text-red-900">Retirer</button>
        </form>
        <form id="retirer-{{ $plage->id }}" method="POST" action="{{ route('admin.plages.retirer', $plage) }}">@csrf @method('DELETE')</form>
    @empty
        <p class="text-sm text-gris">Aucune plage : tous les créneaux sont en attente d'attribution.</p>
    @endforelse

    <h3 class="font-bold">Nouvelle plage</h3>
    <form method="POST" action="{{ route('admin.plages.attribuer') }}" class="flex flex-wrap items-end gap-2">
        @csrf
        <input type="hidden" name="periode_id" value="{{ $periode->id }}">
        @include('admin._champs-plage', ['valeurs' => null])
        <button type="submit" class="h-11 rounded-lg bg-sapin px-4 font-bold text-white">Attribuer</button>
    </form>

    <details>
        <summary class="cursor-pointer text-sm underline">Corriger les dates à la main</summary>
        <form method="POST" action="{{ route('admin.periodes.corriger', $periode) }}" class="mt-2 flex flex-wrap items-end gap-2">
            @csrf @method('PUT')
            <label class="flex flex-col text-xs font-bold">Première nuit<input type="date" name="premiere_nuit" value="{{ $periode->premiere_nuit->format('Y-m-d') }}" required class="{{ $champ }}"></label>
            <label class="flex flex-col text-xs font-bold">Dernier jour<input type="date" name="dernier_jour" value="{{ $periode->dernier_jour->format('Y-m-d') }}" required class="{{ $champ }}"></label>
            <button type="submit" class="h-11 rounded-lg border border-bord px-3 font-bold">Corriger</button>
        </form>
    </details>
</section>
@endforeach

@include('admin._cumul')
@endsection

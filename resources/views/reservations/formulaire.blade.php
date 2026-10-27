@extends('layouts.app')
@section('titre', $modifiee ? 'Modifier ma réservation' : ($joindre ? 'Me joindre à une réservation' : 'Réserver'))
@php($champ = 'h-11 rounded-lg border border-bord bg-white px-3 font-normal')
@section('contenu')

@if ($joindre)
    <p class="rounded-lg bg-sapin-clair p-3 text-sm">Vous rejoignez la réservation de <strong>{{ $joindre->user?->prenom }} {{ $joindre->user?->nom }}</strong>.</p>
@endif

@if ($errors->any())
    <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">
        @foreach ($errors->all() as $erreur)<p>{{ $erreur }}</p>@endforeach
    </div>
@endif

{{-- Étape 1 : les dates (simple formulaire GET, sans script). --}}
<form method="GET" action="{{ $modifiee ? route('reservations.modifier', $modifiee) : route('reservations.formulaire') }}" class="flex flex-wrap items-end gap-3 rounded-2xl border border-bord bg-white p-5">
    @if ($joindre)<input type="hidden" name="joindre" value="{{ $joindre->id }}">@endif
    <label class="flex flex-col gap-1 text-sm font-bold">Du
        <input type="date" name="debut" value="{{ $debut?->format('Y-m-d') }}" required class="{{ $champ }}">
    </label>
    <label class="flex flex-col gap-1 text-sm font-bold">Au
        <input type="date" name="fin" value="{{ $fin?->format('Y-m-d') }}" required class="{{ $champ }}">
    </label>
    <button type="submit" class="h-11 rounded-lg border border-sapin px-4 font-bold text-sapin">Voir les créneaux</button>
</form>

@if ($erreurDates)
    <p role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">{{ $erreurDates }}</p>
@elseif ($lignes !== [])
<form method="POST" action="{{ $modifiee ? route('reservations.mettre-a-jour', $modifiee) : route('reservations.enregistrer') }}" class="flex flex-col gap-5">
    @csrf
    @if ($modifiee) @method('PUT') @endif
    <input type="hidden" name="debut" value="{{ $debut->format('Y-m-d') }}">
    <input type="hidden" name="fin" value="{{ $fin->format('Y-m-d') }}">

    <fieldset class="flex flex-col gap-2 rounded-2xl border border-bord bg-white p-5">
        <legend class="px-1 font-extrabold">Créneaux</legend>
        @foreach ($lignes as $jour)
            <div class="grid grid-cols-[6rem_1fr_1fr] items-center gap-2">
                <span class="text-sm font-bold">{{ ucfirst(\Illuminate\Support\Carbon::instance($jour)->locale('fr')->translatedFormat('D j M')) }}</span>
                @foreach (['J', 'N'] as $t)
                    @php($cle = $jour->format('Y-m-d').'-'.$t)
                    <label class="flex min-h-11 items-center gap-2 {{ $disponibles[$cle] ? '' : 'opacity-60' }}">
                        <input type="checkbox" name="creneaux[]" value="{{ $cle }}" class="size-5"
                               @checked(in_array($cle, $coches, true)) @disabled(! $disponibles[$cle])>
                        @include('partiels.creneau', ['situation' => $situations[$cle]])
                    </label>
                @endforeach
            </div>
        @endforeach
    </fieldset>

    {{-- US-303 : l'encadré des conflits, une aide ; le serveur revérifie tout. --}}
    <section aria-live="polite" class="rounded-2xl border p-4 text-sm {{ $conflits->isEmpty() ? 'border-sapin bg-sapin-clair' : 'border-amber-300 bg-amber-50' }}" data-encadre="conflits">
        @if ($conflits->isEmpty())
            <p><strong>Aucun conflit</strong> sur les créneaux cochés.</p>
        @else
            <p class="font-bold">Créneaux déjà occupés :</p>
            <ul class="list-disc pl-5">
            @foreach ($conflits as $s)
                <li>{{ $s->creneau->date->format('d/m') }} {{ $s->creneau->type->libelle() }} : {{ $s->etat->libelle() }}
                    @if ($s->reservations !== []) avec {{ collect($s->reservations)->map(fn ($r) => $r->user?->prenom)->filter()->implode(', ') }} @endif
                </li>
            @endforeach
            </ul>
            <p class="mt-1 text-gris">L'encadré se met à jour quand vous cliquez sur « Voir les créneaux ».</p>
        @endif
    </section>

    <fieldset class="flex flex-col gap-2 rounded-2xl border border-bord bg-white p-5">
        <legend class="px-1 font-extrabold">Type</legend>
        @php($mode = old('mode', $modifiee?->mode ?? 'ouverte'))
        <label class="flex min-h-11 items-center gap-2"><input type="radio" name="mode" value="ouverte" class="size-5" @checked($mode === 'ouverte' || $joindre)>
            <span><strong>Ouverte</strong> : d'autres membres peuvent se joindre.</span></label>
        <label class="flex min-h-11 items-center gap-2 {{ $raisonPasExclusif || $joindre ? 'opacity-60' : '' }}">
            <input type="radio" name="mode" value="exclusive" class="size-5" @checked($mode === 'exclusive' && ! $joindre) @disabled($raisonPasExclusif || $joindre)>
            <span><strong>Exclusive</strong> : pour vous seul et vos invités.</span></label>
        @if ($raisonPasExclusif)<p class="text-sm text-gris" data-raison="exclusif">{{ $raisonPasExclusif }}</p>@endif
    </fieldset>

    <div class="grid gap-4 rounded-2xl border border-bord bg-white p-5 sm:grid-cols-2">
        <label class="flex flex-col gap-1 text-sm font-bold">Nombre de personnes
            <input type="number" name="nombre_personnes" min="1" max="{{ \App\Domain\Reservations\ServiceReservation::PERSONNES_MAX }}" required
                   value="{{ old('nombre_personnes', $modifiee?->nombre_personnes ?? 1) }}" class="{{ $champ }}">
        </label>
        <label class="flex flex-col gap-1 text-sm font-bold">Invités extérieurs <span class="font-normal text-gris">(facultatif)</span>
            <input name="invites" maxlength="500" value="{{ old('invites', $modifiee?->invites) }}" class="{{ $champ }}">
        </label>
        <label class="flex flex-col gap-1 text-sm font-bold sm:col-span-2">Commentaire <span class="font-normal text-gris">(facultatif)</span>
            <textarea name="commentaire" maxlength="500" rows="2" class="rounded-lg border border-bord p-3 font-normal">{{ old('commentaire', $modifiee?->commentaire) }}</textarea>
        </label>
    </div>

    <button type="submit" class="h-12 self-start rounded-lg bg-sapin px-6 font-bold text-white">{{ $modifiee ? 'Enregistrer les modifications' : 'Valider la réservation' }}</button>
</form>
@endif
@endsection

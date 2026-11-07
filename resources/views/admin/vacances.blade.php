@extends('layouts.app')
@section('titre', 'Vacances et attributions')
@php($champ = 'h-11 rounded-lg border border-bord bg-white px-2 font-normal')
@section('large', '1')
@section('contenu')
@if ($errors->any())
    <div role="alert" class="rounded-lg border border-erreur bg-erreur-fond p-3 text-sm text-erreur">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
@endif

<nav class="flex flex-wrap gap-2" aria-label="Années scolaires">
    @forelse ($annees as $a)
        <a href="{{ route('admin.vacances', ['annee' => $a->libelle]) }}" class="flex min-h-11 items-center rounded-lg border px-3 {{ $annee?->is($a) ? 'border-sapin bg-sapin-clair font-bold' : 'border-bord bg-white' }}">
            {{ $a->libelle }} {{ $a->ouverte ? '' : '(pas encore ouverte)' }}</a>
    @empty
        <p class="text-sm text-gris">Aucune année connue : relisez le calendrier scolaire ou saisissez les dates à la main ci-dessous.</p>
    @endforelse
</nav>

{{-- Calendrier scolaire : dernière lecture, relecture immédiate, saisie à la main (7 nov. 2026). --}}
<section class="flex flex-col gap-3 rounded-2xl border border-bord bg-white p-5" aria-labelledby="calendrier-scolaire" data-calendrier-scolaire>
    <h2 id="calendrier-scolaire" class="text-lg font-extrabold">Calendrier scolaire officiel</h2>
    <p class="text-sm">
        @if ($consultation === null)
            Pas encore lu.
        @elseif ($consultation['echecs'] === [])
            Dernière lecture le {{ $consultation['le']->copy()->timezone('Europe/Paris')->format('d/m/Y à H:i') }} : réussie.
        @else
            <strong class="text-erreur">Dernière lecture le {{ $consultation['le']->copy()->timezone('Europe/Paris')->format('d/m/Y à H:i') }} : en échec.</strong>
        @endif
        Le site relit la source le 1er de chaque mois et à chaque mise à jour du site ; une année s'ouvre à la réservation dès que ses vacances de Noël et de février sont connues.
    </p>
    @if ($consultation !== null && $consultation['echecs'] !== [])
        <ul class="list-disc pl-5 text-sm">@foreach ($consultation['echecs'] as $e)<li>{{ $e }}</li>@endforeach</ul>
    @endif
    <div class="flex flex-wrap items-start gap-2">
        <form method="POST" action="{{ route('admin.vacances.relire') }}">
            @csrf
            <button type="submit" class="h-11 rounded-lg border border-bord px-3 font-bold">Relire le calendrier maintenant</button>
        </form>
    </div>
    <details @if ($errors->has('saisie') || old('annee')) open @endif>
        <summary class="cursor-pointer text-sm underline">Saisir les dates d'une année à la main (si la source est muette)</summary>
        <form method="POST" action="{{ route('admin.vacances.saisir') }}" class="mt-2 flex flex-col gap-3">
            @csrf
            <label class="flex w-40 flex-col text-xs font-bold">Année scolaire<input name="annee" value="{{ old('annee', $anneeASaisir) }}" required pattern="\d{4}-\d{4}" placeholder="2027-2028" class="{{ $champ }}"></label>
            <div class="flex flex-wrap gap-2">
                <label class="flex flex-col text-xs font-bold">Noël, première nuit<input type="date" name="noel_premiere_nuit" value="{{ old('noel_premiere_nuit') }}" required class="{{ $champ }}"></label>
                <label class="flex flex-col text-xs font-bold">Noël, dernier jour<input type="date" name="noel_dernier_jour" value="{{ old('noel_dernier_jour') }}" required class="{{ $champ }}"></label>
            </div>
            <div class="flex flex-wrap gap-2">
                <label class="flex flex-col text-xs font-bold">Février, première nuit<input type="date" name="hiver_premiere_nuit" value="{{ old('hiver_premiere_nuit') }}" required class="{{ $champ }}"></label>
                <label class="flex flex-col text-xs font-bold">Février, dernier jour<input type="date" name="hiver_dernier_jour" value="{{ old('hiver_dernier_jour') }}" required class="{{ $champ }}"></label>
            </div>
            <p class="text-xs text-gris">Les dates saisies sont marquées « corrigées à la main » : la source officielle ne les écrasera pas, un écart vous sera signalé. L'année s'ouvre à la réservation et les membres sont prévenus.</p>
            <button type="submit" class="h-11 self-start rounded-lg bg-sapin px-3 font-bold text-white">Enregistrer et ouvrir l'année</button>
        </form>
    </details>
</section>

@if ($conflits->isNotEmpty())
<section class="flex flex-col gap-3 rounded-2xl border border-alerte bg-alerte-fond p-5" aria-labelledby="conflits">
    <h2 id="conflits" class="text-lg font-extrabold">Réservations en conflit avec les vacances</h2>
    @foreach ($conflits as $r)
        <form method="POST" action="{{ route('admin.conflits.arbitrer', $r) }}" class="flex flex-wrap items-end gap-2 rounded-lg bg-white p-3" data-conflit="{{ $r->id }}">
            @csrf
            <span class="mr-auto text-sm"><strong>{{ $r->user?->prenom }} {{ $r->user?->nom }}</strong>,
                du {{ $r->creneaux->first()?->date->format('d/m/Y') }} au {{ $r->creneaux->last()?->date->format('d/m/Y') }}</span>
            <label class="flex flex-col text-xs font-bold">Motif (envoyé au membre)<input name="motif" required minlength="3" maxlength="500" class="{{ $champ }}"></label>
            <button name="decision" value="maintenir" class="h-11 rounded-lg border border-bord px-3 font-bold">Maintenir</button>
            <button name="decision" value="annuler" class="h-11 rounded-lg bg-erreur px-3 font-bold text-white">Annuler la réservation</button>
        </form>
    @endforeach
</section>
@endif

@if ($periodes->isNotEmpty())
    <p class="text-sm text-gris">Pour attribuer des vacances, cliquez sur un créneau « En attente », puis sur un second pour choisir la période : une pop-up demande la lignée. Cliquez sur un créneau attribué pour changer sa lignée, ses dates, ou retirer l'attribution. Les admins peuvent aussi le faire depuis les vues Mois et Saison.</p>
@endif

@foreach ($periodes as $periode)
<section class="flex flex-col gap-4 rounded-2xl border border-bord bg-white p-5" data-periode="{{ $periode->nom }}">
    <div class="flex flex-wrap items-baseline gap-2">
        <h2 class="text-lg font-extrabold">{{ $periode->libelle() }} {{ $annee->libelle }}</h2>
        <span class="text-sm">du {{ $periode->premiere_nuit->format('d/m/Y') }} (nuit) au {{ $periode->dernier_jour->format('d/m/Y') }} (jour)</span>
        @if ($periode->manuelle)<span class="rounded bg-amber-100 px-2 text-xs font-bold">corrigée à la main</span>@endif
    </div>

    {{-- Les jours comme dans la vue mois (demande du 7 nov.) : on attribue comme on réserve, par clics puis pop-up. --}}
    @include('partiels.grille-semaines', [
        'semaines' => $semaines[$periode->id],
        'affichages' => $affichages[$periode->id],
        'legende' => 'Créneaux de '.$periode->libelle().' '.$annee->libelle,
        'dansVue' => fn ($jour) => $jour->format('Y-m-d') >= $periode->premiere_nuit->format('Y-m-d') && $jour->format('Y-m-d') <= $periode->dernier_jour->format('Y-m-d'),
    ])


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
@include('partiels.popup-attribution')
@endsection

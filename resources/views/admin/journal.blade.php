@extends('layouts.app')
@section('titre', 'Journal des actions')
@php($champ = 'h-11 rounded-lg border border-bord bg-white px-2 font-normal')
@section('contenu')
<form method="GET" class="flex flex-wrap items-end gap-2 rounded-2xl border border-bord bg-white p-4">
    <label class="flex flex-col text-xs font-bold">Du<input type="date" name="du" value="{{ $filtres['du'] ?? '' }}" class="{{ $champ }}"></label>
    <label class="flex flex-col text-xs font-bold">Au<input type="date" name="au" value="{{ $filtres['au'] ?? '' }}" class="{{ $champ }}"></label>
    <label class="flex flex-col text-xs font-bold">Qui
        <select name="auteur" class="{{ $champ }}"><option value="">Tout le monde</option>
            @foreach ($auteurs as $a)<option value="{{ $a->id }}" @selected((int) ($filtres['auteur'] ?? 0) === $a->id)>{{ $a->prenom }} {{ $a->nom }}</option>@endforeach
        </select></label>
    <label class="flex flex-col text-xs font-bold">Action
        <select name="type" class="{{ $champ }}"><option value="">Toutes</option>
            @foreach ($types as $code => $libelle)<option value="{{ $code }}" @selected(($filtres['type'] ?? '') === $code)>{{ $libelle }}</option>@endforeach
        </select></label>
    <button class="h-11 rounded-lg bg-sapin px-4 font-bold text-white">Filtrer</button>
</form>

@forelse ($parJour as $jour => $liste)
    @php($date = \Illuminate\Support\Carbon::parse($jour, 'Europe/Paris'))
    @php($date->locale('fr'))
    <section class="flex flex-col gap-2" data-jour="{{ $jour }}">
        <h2 class="text-sm font-bold text-gris">{{ \Illuminate\Support\Str::ucfirst($date->translatedFormat('l j F Y')) }}</h2>
        <ul class="rounded-2xl border border-bord bg-white">
            @foreach ($liste as $e)
                @php($objet = $lecture->objet($e))
                @php($via = $lecture->via($e))
                <li class="flex gap-3 border-t border-bord p-3 first:border-t-0" data-entree="{{ $e->id }}">
                    <span class="w-12 shrink-0 pt-0.5 text-sm text-gris tabular-nums">{{ $e->cree_le?->timezone('Europe/Paris')->format('H:i') }}</span>
                    <div class="flex min-w-0 flex-col gap-0.5">
                        <p><strong>{{ $lecture::action($e->action) }}</strong>@if ($objet) <span class="text-gris">·</span> {{ $objet }}@endif</p>
                        <p class="text-sm text-gris">par {{ $lecture->auteur($e) }}@if ($via) <span>({{ $via }})</span>@endif</p>
                        @if ($lignes = $lecture->details($e))
                            <ul class="mt-1 text-sm">
                                @foreach ($lignes as $ligne)<li class="break-words">{{ $ligne }}</li>@endforeach
                            </ul>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
@empty
    <p class="rounded-2xl border border-bord bg-white p-4 text-gris">Aucune action pour ces critères.</p>
@endforelse
{{ $entrees->links() }}
@endsection

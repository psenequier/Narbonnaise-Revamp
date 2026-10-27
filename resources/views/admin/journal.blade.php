@extends('layouts.app')
@section('titre', 'Journal des actions')
@php($champ = 'h-11 rounded-lg border border-bord bg-white px-2 font-normal')
@section('contenu')
<form method="GET" class="flex flex-wrap items-end gap-2 rounded-2xl border border-bord bg-white p-4">
    <label class="flex flex-col text-xs font-bold">Du<input type="date" name="du" value="{{ $filtres['du'] ?? '' }}" class="{{ $champ }}"></label>
    <label class="flex flex-col text-xs font-bold">Au<input type="date" name="au" value="{{ $filtres['au'] ?? '' }}" class="{{ $champ }}"></label>
    <label class="flex flex-col text-xs font-bold">Auteur
        <select name="auteur" class="{{ $champ }}"><option value="">Tous</option>
            @foreach ($auteurs as $a)<option value="{{ $a->id }}" @selected((int) ($filtres['auteur'] ?? 0) === $a->id)>{{ $a->prenom }} {{ $a->nom }}</option>@endforeach
        </select></label>
    <label class="flex flex-col text-xs font-bold">Type
        <select name="type" class="{{ $champ }}"><option value="">Tous</option>
            @foreach ($types as $t)<option value="{{ $t }}" @selected(($filtres['type'] ?? '') === $t)>{{ $t }}</option>@endforeach
        </select></label>
    <button class="h-11 rounded-lg bg-sapin px-4 font-bold text-white">Filtrer</button>
</form>

<table class="w-full rounded-2xl border border-bord bg-white text-sm">
    <thead><tr class="text-left"><th scope="col" class="p-2">Quand</th><th scope="col" class="p-2">Auteur</th><th scope="col" class="p-2">Action</th><th scope="col" class="p-2">Objet</th><th scope="col" class="p-2">Détails</th></tr></thead>
    <tbody>
    @forelse ($entrees as $e)
        <tr class="border-t border-bord align-top" data-entree="{{ $e->id }}">
            <td class="p-2 whitespace-nowrap">{{ $e->cree_le?->timezone('Europe/Paris')->format('d/m/Y H:i') }}</td>
            <td class="p-2">{{ $e->user_id ? ($auteurs[$e->user_id]->prenom ?? '').' '.($auteurs[$e->user_id]->nom ?? '') : 'Système' }}</td>
            <td class="p-2 font-mono text-xs">{{ $e->action }}</td>
            <td class="p-2">{{ $e->objet_type }} {{ $e->objet_id }}</td>
            <td class="p-2"><code class="text-xs break-all">{{ json_encode($e->details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</code></td>
        </tr>
    @empty
        <tr><td colspan="5" class="p-3 text-gris">Aucune entrée.</td></tr>
    @endforelse
    </tbody>
</table>
{{ $entrees->links() }}
@endsection

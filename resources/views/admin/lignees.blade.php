@extends('layouts.app')
@section('titre', 'Lignées')
@php($champ = 'h-11 rounded-lg border border-bord px-3 font-normal')
@section('contenu')
<p class="text-sm text-gris">Le nom apparaît dans le calendrier et les attributions de vacances. Les créneaux attribués ont tous la même couleur, quelle que soit la lignée : la couleur choisie ici n'est plus affichée dans le calendrier.</p>
@if (session('status'))<p role="status" class="mt-3 text-sm">{{ session('status') }}</p>@endif

<ul class="mt-4 grid gap-3">
@foreach ($lignees as $lignee)
    <li class="rounded-2xl border border-bord bg-white p-5">
        <form method="POST" action="{{ route('admin.lignees.modifier', $lignee) }}" class="flex flex-wrap items-end gap-4">
            @csrf @method('PUT')
            <label class="flex flex-col gap-1 text-sm font-bold">Nom
                <input name="nom" value="{{ old('nom', $lignee->nom) }}" required maxlength="60" class="{{ $champ }}">
            </label>
            <label class="flex flex-col gap-1 text-sm font-bold">Couleur
                <input type="color" name="couleur" value="{{ old('couleur', strtolower($lignee->couleur)) }}" required class="h-11 w-20 rounded-lg border border-bord">
            </label>
            <button class="h-11 rounded-lg bg-sapin px-4 font-bold text-white">Enregistrer</button>
        </form>
        @foreach ($errors->getBag('lignee'.$lignee->id)->all() as $erreur)<p role="alert" class="mt-2 text-sm text-erreur">{{ $erreur }}</p>@endforeach
    </li>
@endforeach
</ul>
@endsection

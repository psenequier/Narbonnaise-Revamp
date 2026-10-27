@extends('layouts.app')
@section('titre', 'Utilisateurs')
@php($champ = 'h-11 rounded-lg border border-bord bg-white px-3 font-normal')
@section('contenu')
@if ($errors->any())
    <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
@endif

<section class="rounded-2xl border border-bord bg-white p-5" aria-labelledby="nouveau">
    <h2 id="nouveau" class="mb-3 text-lg font-extrabold">Inviter un membre</h2>
    {{-- US-601-N3 : aucun champ de mot de passe ; le membre le choisit depuis l'invitation. --}}
    <form method="POST" action="{{ route('admin.utilisateurs.creer') }}" class="grid gap-3 sm:grid-cols-2">
        @csrf
        <label class="flex flex-col gap-1 text-sm font-bold">Prénom<input name="prenom" value="{{ old('prenom') }}" required maxlength="80" class="{{ $champ }}"></label>
        <label class="flex flex-col gap-1 text-sm font-bold">Nom<input name="nom" value="{{ old('nom') }}" required maxlength="80" class="{{ $champ }}"></label>
        <label class="flex flex-col gap-1 text-sm font-bold">Adresse e-mail<input type="email" name="email" value="{{ old('email') }}" required class="{{ $champ }}"></label>
        <label class="flex flex-col gap-1 text-sm font-bold">Rôle
            <select name="role" class="{{ $champ }}"><option value="membre">Membre</option><option value="admin" @selected(old('role') === 'admin')>Administrateur</option></select>
        </label>
        <div><button type="submit" class="h-11 rounded-lg bg-sapin px-4 font-bold text-white">Créer et envoyer l'invitation</button></div>
    </form>
</section>

<table class="w-full rounded-2xl border border-bord bg-white text-sm">
    <caption class="sr-only">Comptes</caption>
    <thead><tr class="text-left"><th scope="col" class="p-3">Nom</th><th scope="col" class="p-3">E-mail</th><th scope="col" class="p-3">Rôle</th><th scope="col" class="p-3">Statut</th></tr></thead>
    <tbody>
    @foreach ($utilisateurs as $u)
        <tr class="border-t border-bord" data-utilisateur="{{ $u->id }}">
            <td class="p-3"><a href="{{ route('admin.utilisateurs.fiche', $u) }}" class="font-bold underline">{{ $u->prenom }} {{ $u->nom }}</a></td>
            <td class="p-3">{{ $u->email }}</td>
            <td class="p-3">{{ $u->estAdmin() ? 'Administrateur' : 'Membre' }}</td>
            <td class="p-3" data-statut="{{ $u->statut() }}">{{ $u->statut() }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
@endsection

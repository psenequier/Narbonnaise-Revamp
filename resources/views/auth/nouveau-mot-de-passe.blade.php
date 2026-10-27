@extends('layouts.invite')
@section('titre', 'Nouveau mot de passe')
@section('contenu')
<p class="text-sm text-gris">Au moins 12 caractères, avec majuscule, minuscule, chiffre et symbole.</p>
<form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-4">
    @csrf
    <input type="hidden" name="token" value="{{ $request->route('token') }}">
    <label class="flex flex-col gap-1 text-sm font-bold">Adresse e-mail
        <input type="email" name="email" value="{{ old('email', $request->email) }}" required autocomplete="username" class="h-11 rounded-lg border border-bord px-3 font-normal">
    </label>
    <label class="flex flex-col gap-1 text-sm font-bold">Nouveau mot de passe
        <input type="password" name="password" required autocomplete="new-password" class="h-11 rounded-lg border border-bord px-3 font-normal">
    </label>
    <label class="flex flex-col gap-1 text-sm font-bold">Confirmation
        <input type="password" name="password_confirmation" required autocomplete="new-password" class="h-11 rounded-lg border border-bord px-3 font-normal">
    </label>
    <button type="submit" class="h-11 rounded-lg bg-sapin font-bold text-white">Enregistrer</button>
</form>
@endsection

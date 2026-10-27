@extends('layouts.invite')
@section('titre', 'Confirmer votre mot de passe')
@section('contenu')
<p class="text-sm text-gris">Par sécurité, saisissez à nouveau votre mot de passe pour continuer.</p>
<form method="POST" action="{{ route('password.confirm') }}" class="flex flex-col gap-4">
    @csrf
    <label class="flex flex-col gap-1 text-sm font-bold">Mot de passe
        <input type="password" name="password" required autofocus autocomplete="current-password" class="h-11 rounded-lg border border-bord px-3 font-normal">
    </label>
    <button type="submit" class="h-11 rounded-lg bg-sapin font-bold text-white">Confirmer</button>
</form>
@endsection

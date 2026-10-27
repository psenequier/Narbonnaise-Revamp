@extends('layouts.invite')
@section('titre', 'Connexion')
@section('contenu')
<form method="POST" action="{{ route('login') }}" class="flex flex-col gap-4">
    @csrf
    <label class="flex flex-col gap-1 text-sm font-bold">Adresse e-mail
        <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="h-11 rounded-lg border border-bord px-3 font-normal">
    </label>
    <label class="flex flex-col gap-1 text-sm font-bold">Mot de passe
        <input type="password" name="password" required autocomplete="current-password" class="h-11 rounded-lg border border-bord px-3 font-normal">
    </label>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember"> Rester connecté</label>
    <button type="submit" class="h-11 rounded-lg bg-sapin font-bold text-white">Se connecter</button>
    <a href="{{ route('password.request') }}" class="text-sm text-sapin underline">Mot de passe oublié ?</a>
</form>
@endsection

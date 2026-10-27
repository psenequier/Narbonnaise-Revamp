@extends('layouts.invite')
@section('titre', 'Mot de passe oublié')
@section('contenu')
<p class="text-sm text-gris">Indiquez votre adresse : si elle correspond à un compte, vous recevrez un lien pour choisir un nouveau mot de passe.</p>
<form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-4">
    @csrf
    <label class="flex flex-col gap-1 text-sm font-bold">Adresse e-mail
        <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="h-11 rounded-lg border border-bord px-3 font-normal">
    </label>
    <button type="submit" class="h-11 rounded-lg bg-sapin font-bold text-white">Envoyer le lien</button>
    <a href="{{ route('login') }}" class="text-sm text-sapin underline">Retour à la connexion</a>
</form>
@endsection

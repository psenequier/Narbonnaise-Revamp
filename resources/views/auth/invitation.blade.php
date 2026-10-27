@extends('layouts.invite')
@section('titre', 'Activer mon compte')
@section('contenu')
<p class="text-sm">Bonjour {{ $user->prenom }}, choisissez votre mot de passe : 12 caractères au moins, avec une majuscule, une minuscule, un chiffre et un caractère spécial.</p>
<form method="POST" action="{{ route('invitation.activer', ['user' => $user->id, 'jeton' => $jeton]) }}" class="flex flex-col gap-4">
    @csrf
    <input type="email" value="{{ $user->email }}" autocomplete="username" readonly hidden>
    <label class="flex flex-col gap-1 text-sm font-bold">Mot de passe
        <input type="password" name="password" required autofocus autocomplete="new-password" class="h-11 rounded-lg border border-bord px-3 font-normal">
    </label>
    <label class="flex flex-col gap-1 text-sm font-bold">Confirmation
        <input type="password" name="password_confirmation" required autocomplete="new-password" class="h-11 rounded-lg border border-bord px-3 font-normal">
    </label>
    <button type="submit" class="h-11 rounded-lg bg-sapin font-bold text-white">Activer mon compte</button>
</form>
@endsection

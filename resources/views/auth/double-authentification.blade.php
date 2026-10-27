@extends('layouts.invite')
@section('titre', 'Double authentification')
@section('contenu')
<p class="text-sm text-gris">Saisissez le code à 6 chiffres de votre application d'authentification, ou un code de secours.</p>
<form method="POST" action="{{ route('two-factor.login') }}" class="flex flex-col gap-4">
    @csrf
    <label class="flex flex-col gap-1 text-sm font-bold">Code à 6 chiffres
        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus class="h-11 rounded-lg border border-bord px-3 font-normal">
    </label>
    <label class="flex flex-col gap-1 text-sm font-bold">Ou code de secours
        <input type="text" name="recovery_code" autocomplete="off" class="h-11 rounded-lg border border-bord px-3 font-normal">
    </label>
    <button type="submit" class="h-11 rounded-lg bg-sapin font-bold text-white">Valider</button>
</form>
@endsection

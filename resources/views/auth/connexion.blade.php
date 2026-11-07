@extends('layouts.invite')
@section('titre', 'Connexion')
@section('sous-titre', 'Accès réservé aux membres de la famille.')
@section('contenu')
<form method="POST" action="{{ route('login') }}" class="flex flex-col gap-5">
    @csrf
    <div class="flex flex-col gap-1.5">
        <label for="email" class="font-semibold">Adresse e-mail</label>
        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="champ-invite">
    </div>
    <div class="flex flex-col gap-1.5">
        <div class="flex items-baseline justify-between gap-3">
            <label for="password" class="font-semibold">Mot de passe</label>
            <a href="{{ route('password.request') }}" class="font-semibold text-principale underline hover:text-principale-fonce">Mot de passe oublié ?</a>
        </div>
        <input id="password" type="password" name="password" required autocomplete="current-password" class="champ-invite">
    </div>
    <label class="flex items-center gap-2.5"><input type="checkbox" name="remember" class="size-5 accent-principale"> Rester connecté</label>
    <button type="submit" class="h-[50px] rounded-[14px] bg-principale font-bold text-white hover:bg-principale-fonce">Se connecter</button>
    @if (config('services.google.client_id'))
        <div class="flex items-center gap-3 text-gris before:h-px before:flex-1 before:bg-bord after:h-px after:flex-1 after:bg-bord">ou</div>
        <a href="{{ route('google.redirection') }}" class="flex h-[50px] items-center justify-center gap-2.5 rounded-[14px] border border-bord-fort bg-white font-bold hover:bg-principale-clair">
            <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true"><path d="M21.6 12.2c0-.7-.1-1.4-.2-2H12v3.8h5.4a4.6 4.6 0 01-2 3v2.5h3.2c1.9-1.7 3-4.3 3-7.3z" fill="#4285F4"/><path d="M12 22c2.7 0 5-.9 6.6-2.4l-3.2-2.5c-.9.6-2 1-3.4 1-2.6 0-4.8-1.8-5.6-4.1H3.1v2.6A10 10 0 0012 22z" fill="#34A853"/><path d="M6.4 14c-.2-.6-.3-1.3-.3-2s.1-1.4.3-2V7.4H3.1a10 10 0 000 9.2z" fill="#FBBC05"/><path d="M12 6c1.5 0 2.8.5 3.8 1.5l2.8-2.8A10 10 0 003.1 7.4L6.4 10C7.2 7.8 9.4 6 12 6z" fill="#EA4335"/></svg>
            Continuer avec Google
        </a>
    @endif
    <p class="text-gris">Pas encore de compte ? Demandez-le à l'un des trois administrateurs de la SCI.</p>
</form>
@endsection

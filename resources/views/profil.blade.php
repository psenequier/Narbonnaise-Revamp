@extends('layouts.app')
@section('titre', 'Mon profil')
@php($champ = 'h-11 rounded-lg border border-bord px-3 font-normal')
@php($bouton = 'h-11 rounded-lg bg-sapin px-4 font-bold text-white')
@php($carte = 'flex flex-col gap-4 rounded-2xl border border-bord bg-white p-6')
@section('contenu')

@if ($user->estAdmin() && ! $user->two_factor_confirmed_at)
    <p role="alert" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm">
        En tant qu'admin, vous devez activer la double authentification pour accéder à l'administration.
    </p>
@endif

<section class="{{ $carte }}" aria-labelledby="infos">
    <h2 id="infos" class="text-lg font-extrabold">Mes informations</h2>
    @foreach ($errors->updateProfileInformation->all() as $erreur)<p role="alert" class="text-sm text-red-900">{{ $erreur }}</p>@endforeach
    @if (session('status') === 'profile-information-updated')<p role="status" class="text-sm">Informations enregistrées.</p>@endif
    <form method="POST" action="{{ route('user-profile-information.update') }}" class="grid gap-4 sm:grid-cols-2">
        @csrf @method('PUT')
        <label class="flex flex-col gap-1 text-sm font-bold">Prénom
            <input name="prenom" value="{{ old('prenom', $user->prenom) }}" required autocomplete="given-name" class="{{ $champ }}">
        </label>
        <label class="flex flex-col gap-1 text-sm font-bold">Nom
            <input name="nom" value="{{ old('nom', $user->nom) }}" required autocomplete="family-name" class="{{ $champ }}">
        </label>
        <label class="flex flex-col gap-1 text-sm font-bold sm:col-span-2">Adresse e-mail
            <input type="email" name="email" value="{{ old('email', $user->email) }}" required autocomplete="email" class="{{ $champ }}">
        </label>
        @if ($user->email_en_attente)
            <p class="text-sm text-gris sm:col-span-2">En attente de confirmation : {{ $user->email_en_attente }}. Cliquez sur le lien reçu à cette adresse.</p>
        @endif
        <div><button type="submit" class="{{ $bouton }}">Enregistrer</button></div>
    </form>
</section>

<section class="{{ $carte }}" aria-labelledby="mdp">
    <h2 id="mdp" class="text-lg font-extrabold">Mot de passe</h2>
    @foreach ($errors->updatePassword->all() as $erreur)<p role="alert" class="text-sm text-red-900">{{ $erreur }}</p>@endforeach
    @if (session('status') === 'password-updated')<p role="status" class="text-sm">Mot de passe modifié. Vos autres sessions ont été fermées.</p>@endif
    <form method="POST" action="{{ route('user-password.update') }}" class="flex flex-col gap-4">
        @csrf @method('PUT')
        <input type="email" value="{{ $user->email }}" autocomplete="username" readonly hidden>
        <label class="flex flex-col gap-1 text-sm font-bold">Mot de passe actuel
            <input type="password" name="current_password" required autocomplete="current-password" class="{{ $champ }}">
        </label>
        <label class="flex flex-col gap-1 text-sm font-bold">Nouveau mot de passe
            <input type="password" name="password" required autocomplete="new-password" class="{{ $champ }}">
        </label>
        <label class="flex flex-col gap-1 text-sm font-bold">Confirmation
            <input type="password" name="password_confirmation" required autocomplete="new-password" class="{{ $champ }}">
        </label>
        <div><button type="submit" class="{{ $bouton }}">Changer le mot de passe</button></div>
    </form>
</section>

<section class="{{ $carte }}" aria-labelledby="deuxfa">
    <h2 id="deuxfa" class="text-lg font-extrabold">Double authentification</h2>
    @if ($user->two_factor_confirmed_at)
        <p class="text-sm">Activée. Un code de votre application vous sera demandé à chaque connexion.</p>
        @if (session('status') === 'two-factor-authentication-confirmed')
            <div class="flex flex-col gap-2 rounded-lg bg-fond p-4">
                <p class="text-sm font-bold">Codes de secours, à conserver en lieu sûr (chacun ne sert qu'une fois) :</p>
                <ul class="grid grid-cols-2 gap-1 font-mono text-sm">@foreach ($user->recoveryCodes() as $code)<li>{{ $code }}</li>@endforeach</ul>
            </div>
        @endif
        @unless ($user->estAdmin())
            <form method="POST" action="{{ route('two-factor.disable') }}">@csrf @method('DELETE')
                <button type="submit" class="h-11 rounded-lg border border-bord px-4 font-bold">Désactiver</button>
            </form>
        @endunless
    @elseif ($user->two_factor_secret)
        <p class="text-sm">Scannez ce QR code avec votre application d'authentification, puis saisissez le code affiché.</p>
        <img src="data:image/svg+xml;base64,{{ base64_encode($user->twoFactorQrCodeSvg()) }}" alt="QR code à scanner avec votre application d'authentification" class="w-48">
        @foreach ($errors->confirmTwoFactorAuthentication->all() as $erreur)<p role="alert" class="text-sm text-red-900">{{ $erreur }}</p>@endforeach
        <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex items-end gap-3">
            @csrf
            <label class="flex flex-col gap-1 text-sm font-bold">Code à 6 chiffres
                <input name="code" inputmode="numeric" autocomplete="one-time-code" required class="{{ $champ }}">
            </label>
            <button type="submit" class="{{ $bouton }}">Confirmer</button>
        </form>
    @else
        <p class="text-sm">Ajoutez un code d'application (Google Authenticator, Microsoft Authenticator…) à votre mot de passe.</p>
        <form method="POST" action="{{ route('two-factor.enable') }}">@csrf
            <button type="submit" class="{{ $bouton }}">Activer</button>
        </form>
    @endif
</section>

<section class="{{ $carte }}" aria-labelledby="google">
    <h2 id="google" class="text-lg font-extrabold">Compte Google</h2>
    @error('google')<p role="alert" class="text-sm text-red-900">{{ $message }}</p>@enderror
    @if ($user->google_id)
        <p class="text-sm">Un compte Google est lié : vous pouvez l'utiliser pour vous connecter.</p>
        <form method="POST" action="{{ route('profil.google.delier') }}">@csrf @method('DELETE')
            <button type="submit" class="h-11 rounded-lg border border-bord px-4 font-bold">Délier</button>
        </form>
    @else
        <p class="text-sm">Liez un compte Google, même d'une autre adresse, pour vous connecter sans mot de passe.</p>
        <a href="{{ route('profil.google.lier') }}" class="self-start rounded-lg border border-bord px-4 py-2.5 font-bold">Lier mon compte Google</a>
    @endif
</section>

<section class="{{ $carte }}" aria-labelledby="prefs">
    <h2 id="prefs" class="text-lg font-extrabold">E-mails d'information</h2>
    <p class="text-sm text-gris">Les alertes de sécurité et les e-mails sur vos propres créneaux sont toujours envoyés.</p>
    <form method="POST" action="{{ route('profil.preferences') }}" class="flex flex-col gap-3">
        @csrf @method('PUT')
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="recoit_emails_autres" value="1" @checked($user->recoit_emails_autres)> Réservations des autres membres</label>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="recoit_annonces_attribution" value="1" @checked($user->recoit_annonces_attribution)> Annonces d'attribution</label>
        <div><button type="submit" class="{{ $bouton }}">Enregistrer</button></div>
    </form>
</section>
@endsection

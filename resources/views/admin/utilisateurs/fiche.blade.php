@extends('layouts.app')
@section('titre', $membre->prenom.' '.$membre->nom)
@php($bouton = 'h-11 rounded-lg border border-bord px-4 font-bold')
@section('contenu')
<a href="{{ route('admin.utilisateurs') }}" class="text-sm underline">‹ Tous les utilisateurs</a>
@if ($errors->any())
    <div role="alert" class="rounded-lg border border-erreur bg-erreur-fond p-3 text-sm text-erreur">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
@endif

@if ($membre->supprime_le !== null)
<section class="flex flex-col gap-2 rounded-2xl border border-bord bg-white p-5" data-supprime>
    <p><strong data-statut="Supprimé">Compte supprimé</strong> le {{ $membre->supprime_le->timezone('Europe/Paris')->format('d/m/Y') }} : seul le prénom est conservé (US-110).</p>
</section>
@else
@if ($membre->suppression_demandee_le !== null)
<section class="flex flex-col gap-3 rounded-2xl border border-erreur bg-erreur-fond p-5" aria-labelledby="suppression" data-suppression-demandee>
    <h2 id="suppression" class="text-lg font-extrabold">Suppression demandée le {{ $membre->suppression_demandee_le->timezone('Europe/Paris')->format('d/m/Y') }}</h2>
    <p class="text-sm">Seul le prénom sera conservé. Le nom, l'adresse e-mail, le mot de passe et le compte Google lié seront effacés, y compris du journal et de la boîte d'envoi. Les réservations passées restent, avec le prénom. Ses réservations à venir seront annulées et les créneaux libérés. Cette action ne se défait pas.</p>
    <form method="POST" action="{{ route('admin.utilisateurs.supprimer', $membre) }}">@csrf<button class="h-11 rounded-lg bg-erreur px-4 font-bold text-white">Supprimer le compte</button></form>
</section>
@endif
<section class="flex flex-col gap-2 rounded-2xl border border-bord bg-white p-5">
    <p>{{ $membre->email }} · <strong data-statut="{{ $membre->statut() }}">{{ $membre->statut() }}</strong> · {{ $membre->estAdmin() ? 'Administrateur' : 'Membre' }}</p>
    <div class="flex flex-wrap gap-2">
        @if ($membre->statut() === 'Invité')
            <form method="POST" action="{{ route('admin.utilisateurs.invitation', $membre) }}">@csrf<button class="{{ $bouton }}">Renvoyer l'invitation</button></form>
        @endif
        @if ($membre->statut() === 'Actif')
            {{-- US-603 : le lien part au membre ; l'admin ne le voit jamais. --}}
            <form method="POST" action="{{ route('admin.utilisateurs.reinitialisation', $membre) }}">@csrf<button class="{{ $bouton }}">Envoyer un lien de réinitialisation</button></form>
        @endif
        @if ($membre->actif)
            <form method="POST" action="{{ route('admin.utilisateurs.desactiver', $membre) }}">@csrf<button class="h-11 rounded-lg bg-erreur px-4 font-bold text-white">Désactiver</button></form>
        @else
            <form method="POST" action="{{ route('admin.utilisateurs.reactiver', $membre) }}">@csrf<button class="{{ $bouton }}">Réactiver</button></form>
        @endif
        <form method="POST" action="{{ route('admin.utilisateurs.role', $membre) }}" class="flex gap-2">
            @csrf @method('PUT')
            <select name="role" class="h-11 rounded-lg border border-bord px-2" aria-label="Rôle">
                <option value="membre" @selected(! $membre->estAdmin())>Membre</option><option value="admin" @selected($membre->estAdmin())>Administrateur</option>
            </select>
            <button class="{{ $bouton }}">Changer le rôle</button>
        </form>
    </div>
</section>

@endif

<section class="flex flex-col gap-2 rounded-2xl border border-bord bg-white p-5" aria-labelledby="futures">
    <h2 id="futures" class="text-lg font-extrabold">Réservations à venir</h2>
    @forelse ($futures as $r)
        <p data-reservation="{{ $r->id }}">Du {{ $r->creneaux->first()?->date->format('d/m/Y') }} au {{ $r->creneaux->last()?->date->format('d/m/Y') }}
            · {{ $r->mode === 'exclusive' ? 'Exclusive' : 'Ouverte' }}
            · <a href="{{ route('jour', $r->creneaux->first()?->date->format('Y-m-d')) }}" class="underline">voir</a></p>
    @empty
        <p class="text-sm text-gris">Aucune réservation à venir.</p>
    @endforelse
</section>
@endsection

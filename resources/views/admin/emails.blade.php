@extends('layouts.app')
@section('titre', 'E-mails')
@php($champ = 'h-11 rounded-lg border border-bord bg-white px-2 font-normal')
@section('contenu')
<section class="flex flex-col gap-3 rounded-2xl border border-bord bg-white p-5" aria-labelledby="redirection">
    <h2 id="redirection" class="text-lg font-extrabold">Redirection pendant les essais</h2>
    @if ($adresse !== null)
        <p class="rounded-lg border border-alerte bg-alerte-fond p-3 text-sm" data-redirection="active">Tous les e-mails du site partent vers <strong>{{ $adresse }}</strong>, et vers personne d'autre ({{ $source === 'admin' ? 'réglé ici' : 'réglage du serveur' }}).</p>
    @else
        <p class="rounded-lg bg-sapin-clair p-3 text-sm" data-redirection="aucune">Pas de redirection : les e-mails partent vers leurs vrais destinataires.</p>
    @endif
    <form method="POST" action="{{ route('admin.emails.redirection') }}" class="flex flex-wrap items-end gap-2 text-sm">
        @csrf
        @method('PUT')
        <input type="hidden" name="mode" value="adresse">
        <label class="flex flex-col font-bold">Rediriger vers<input type="email" name="adresse" value="{{ old('adresse', $adresse) }}" required maxlength="190" class="{{ $champ }}"></label>
        <button class="h-11 rounded-lg bg-sapin px-3 font-bold text-white">Rediriger</button>
    </form>
    @error('adresse')<p class="text-sm text-erreur">{{ $message }}</p>@enderror
    <form method="POST" action="{{ route('admin.emails.redirection') }}" class="flex flex-wrap items-center gap-2 text-sm">
        @csrf
        @method('PUT')
        <input type="hidden" name="mode" value="aucune">
        <label class="flex items-center gap-2"><input type="checkbox" name="confirmer" value="1"> Les e-mails partiront vers les vrais membres</label>
        <button class="h-11 rounded-lg border border-erreur px-3 font-bold text-erreur">Couper la redirection</button>
    </form>
    @error('confirmer')<p class="text-sm text-erreur">{{ $message }}</p>@enderror
    @if ($source === 'admin')
        <form method="POST" action="{{ route('admin.emails.redirection') }}" class="text-sm">
            @csrf
            @method('PUT')
            <input type="hidden" name="mode" value="serveur">
            <button class="font-bold text-sapin underline">Revenir au réglage du serveur</button>
        </form>
    @endif
    <p class="text-sm text-gris">À couper le jour de la bascule, avant d'envoyer la nouvelle adresse aux membres.</p>
</section>

<section class="flex flex-col gap-3 rounded-2xl border border-bord bg-white p-5" aria-labelledby="test">
    <h2 id="test" class="text-lg font-extrabold">E-mail de test</h2>
    <form method="POST" action="{{ route('admin.emails.test') }}">
        @csrf
        <button class="h-11 rounded-lg border border-sapin px-3 text-sm font-bold text-sapin">M'envoyer un e-mail de test</button>
    </form>
    @error('test')<p class="text-sm text-erreur">{{ $message }}</p>@enderror
</section>

<section class="flex flex-col gap-3 rounded-2xl border border-bord bg-white p-5" aria-labelledby="envois">
    <h2 id="envois" class="text-lg font-extrabold">Derniers envois</h2>
    <p class="text-sm text-gris">{{ $heure }} destinataire(s) dans la dernière heure, sur {{ $plafond }} au plus. « Parti » veut dire remis au serveur d'envoi d'OVH : un e-mail parti peut encore finir dans les spams.</p>
    <div class="overflow-x-auto">
    <table class="w-full min-w-[640px] text-left text-sm">
        <thead><tr class="border-b border-bord text-xs text-gris uppercase"><th class="py-2 pr-3">Déposé</th><th class="py-2 pr-3">Sujet</th><th class="py-2 pr-3">Dest.</th><th class="py-2 pr-3">État</th></tr></thead>
        <tbody>
        @forelse ($envois as $e)
            <tr class="border-b border-bord-doux align-top">
                <td class="py-2 pr-3 tabular-nums whitespace-nowrap">{{ $e['depose'] }}</td>
                <td class="py-2 pr-3">{{ $e['sujet'] }}</td>
                <td class="py-2 pr-3 tabular-nums">{{ $e['destinataires'] }}</td>
                <td class="py-2 pr-3">
                    @if ($e['envoye'] !== null)
                        <span class="font-bold text-sapin">Parti</span> à {{ $e['envoye'] }}
                    @elseif ($e['erreur'] !== null)
                        <span class="font-bold text-erreur">En échec</span> ({{ $e['tentatives'] }} essai(s)) : {{ $e['erreur'] }}
                    @else
                        <span class="font-bold">En attente</span>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="py-3 text-gris">Aucun e-mail pour l'instant.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</section>
@endsection

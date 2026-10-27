@extends('layouts.app')
@section('titre', 'Reprise de GRR')
@section('contenu')
@if ($errors->has('reprise'))
    <p role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">{{ $errors->first('reprise') }}</p>
@endif
@if ($ouverte)
    <form method="POST" action="{{ route('admin.reprise.lancer') }}" class="flex flex-wrap items-center gap-3 rounded-2xl border border-bord bg-white p-5">
        @csrf
        <p class="mr-auto text-sm">Reprend comptes et réservations depuis les tables GRR, puis contrôle. Relançable sans doublon ; GRR n'est jamais modifié.</p>
        <button class="h-11 rounded-lg bg-sapin px-4 font-bold text-white">Lancer la reprise</button>
    </form>
@endif
<section class="flex flex-col gap-3 rounded-2xl border border-bord bg-white p-5" aria-labelledby="annonces">
    <h2 id="annonces" class="text-lg font-extrabold">Annonces de la bascule</h2>
    <form method="POST" action="{{ route('admin.reprise.annoncer') }}" class="flex flex-wrap items-end gap-2 text-sm">
        @csrf
        <label class="flex flex-col font-bold">Date de la bascule<input type="date" name="date" required class="h-11 rounded-lg border border-bord px-2"></label>
        <label class="flex flex-col font-bold">Durée de la coupure<input name="duree" value="une nuit, de 22 h à 6 h" required maxlength="100" class="h-11 rounded-lg border border-bord px-2"></label>
        <button class="h-11 rounded-lg border border-sapin px-3 font-bold text-sapin">Annoncer (au moins 7 jours avant)</button>
    </form>
    <form method="POST" action="{{ route('admin.reprise.mise-en-service') }}">
        @csrf
        <button class="h-11 rounded-lg bg-sapin px-3 text-sm font-bold text-white">Jour J : envoyer la nouvelle adresse et les liens de mot de passe</button>
    </form>
</section>
@if ($controle === null)
    <p class="rounded-lg border border-bord bg-white p-4">Aucune reprise lancée.</p>
@else
<section class="rounded-2xl border p-5 {{ $controle['valide'] ? 'border-sapin bg-sapin-clair' : 'border-red-300 bg-red-50' }}" data-controle="{{ $controle['valide'] ? 'valide' : 'non-valide' }}">
    <h2 class="text-lg font-extrabold">{{ $controle['valide'] ? 'Reprise validée' : 'Reprise non validée' }}</h2>
    <p class="text-sm">Comptes GRR : {{ $controle['comptes']['grr'] }} = {{ $controle['comptes']['repris'] }} repris + {{ $controle['comptes']['anomalies'] }} en anomalie</p>
    <table class="mt-3 w-full text-sm">
        <thead><tr class="text-left"><th>Année</th><th>Réservations GRR</th><th>Site</th><th>Nuits GRR</th><th>Site</th><th>Écart</th></tr></thead>
        <tbody>
        @foreach ($controle['annees'] as $annee => $a)
            <tr data-annee="{{ $annee }}" data-ecart="{{ $a['ecart'] ? 'oui' : 'non' }}"><td>{{ $annee }}</td><td>{{ $a['grr_reservations'] }}</td><td>{{ $a['site_reservations'] }}</td>
                <td>{{ $a['grr_nuits'] }}</td><td>{{ $a['site_nuits'] }}</td><td>{{ $a['ecart'] ? 'ÉCART' : '' }}</td></tr>
        @endforeach
        </tbody>
    </table>
</section>

<section class="rounded-2xl border border-bord bg-white p-5">
    <h2 class="mb-2 text-lg font-extrabold">{{ count($controle['echantillon']) }} réservations tirées au hasard</h2>
    <ul class="flex flex-col gap-1 text-sm">
    @foreach ($controle['echantillon'] as $e)<li data-echantillon><strong>GRR</strong> {{ $e['grr'] }}<br><strong>Site</strong> {{ $e['site'] }}</li>@endforeach
    </ul>
</section>
@endif

@if ($import)
<section class="rounded-2xl border border-bord bg-white p-5 text-sm">
    <h2 class="mb-2 text-lg font-extrabold">Anomalies de l'import</h2>
    @foreach (['a_reinitialiser' => 'Mot de passe à réinitialiser', 'doublons_email' => 'Doublons d\'e-mail', 'comptes_rejetes' => 'Comptes rejetés', 'rejets' => 'Entrées rejetées', 'chevauchements' => 'Chevauchements historiques'] as $cle => $titre)
        @if (! empty($import[$cle]))
            <h3 class="mt-2 font-bold">{{ $titre }} ({{ count($import[$cle]) }})</h3>
            <ul class="list-disc pl-5">@foreach ($import[$cle] as $l)<li>{{ $l }}</li>@endforeach</ul>
        @endif
    @endforeach
</section>
@endif

<section class="rounded-2xl border border-bord bg-white p-5">
    <h2 class="mb-2 text-lg font-extrabold">Anciennes attributions à valider</h2>
    @if ($errors->any())
        <div role="alert" class="mb-2 rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
    @endif
    @forelse ($propositions as $p)
        @php($cles = json_decode($p->creneaux, true))
        <form method="POST" action="{{ route('admin.reprise.valider', $p->id) }}" class="flex flex-wrap items-center gap-2 border-t border-bord py-2 text-sm" data-proposition="{{ $p->id }}">
            @csrf
            <span class="mr-auto">Entrée GRR n° {{ $p->grr_entry_id }} · « {{ $p->libelle }} » · du {{ $cles[0] ?? '' }} au {{ end($cles) }} ({{ count($cles) }} créneaux)</span>
            <select name="lignee_id" required aria-label="Lignée" class="h-11 rounded-lg border border-bord px-2">
                <option value="">Lignée…</option>
                @foreach ($lignees as $l)<option value="{{ $l->id }}" @selected($p->lignee_id === $l->id)>{{ $l->nom }}</option>@endforeach
            </select>
            <button class="h-11 rounded-lg bg-sapin px-3 font-bold text-white">Valider</button>
        </form>
    @empty
        <p class="text-sm text-gris">Aucune.</p>
    @endforelse
</section>
@endsection

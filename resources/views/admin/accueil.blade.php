@extends('layouts.app')
@section('titre', 'Administration')
@section('contenu')
<ul class="grid gap-3 sm:grid-cols-2">
    <li><a href="{{ route('admin.vacances') }}" class="flex min-h-11 flex-col rounded-2xl border border-bord bg-white p-5">
        <strong>Vacances et attributions</strong><span class="text-sm text-gris">Périodes, plages par lignée, conflits</span></a></li>
    <li><a href="{{ route('admin.cumul') }}" class="flex min-h-11 flex-col rounded-2xl border border-bord bg-white p-5">
        <strong>Cumul par lignée</strong><span class="text-sm text-gris">Créneaux attribués depuis le début</span></a></li>
</ul>
<ul class="mt-3 grid gap-3 sm:grid-cols-2">
    <li><a href="{{ route('admin.utilisateurs') }}" class="flex min-h-11 flex-col rounded-2xl border border-bord bg-white p-5">
        <strong>Utilisateurs</strong><span class="text-sm text-gris">Invitations, désactivation, rôles, réinitialisation</span></a></li>
    <li><a href="{{ route('admin.journal') }}" class="flex min-h-11 flex-col rounded-2xl border border-bord bg-white p-5">
        <strong>Journal des actions</strong><span class="text-sm text-gris">Qui a fait quoi, quand</span></a></li>
</ul>
@endsection

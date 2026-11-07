@extends('layouts.app')
@section('titre', 'Administration')
@section('contenu')
@php
    // Une seule grille : mêmes écarts partout, cartes de même hauteur.
    // « Lignées » apparaît dès que sa page existe (PR des lignées).
    $cartes = array_filter([
        ['admin.vacances', 'Vacances et attributions', 'Périodes, plages par lignée, conflits'],
        ['admin.cumul', 'Cumul par lignée', 'Créneaux attribués depuis le début'],
        Route::has('admin.lignees') ? ['admin.lignees', 'Lignées', 'Nom et couleur'] : null,
        ['admin.utilisateurs', 'Utilisateurs', 'Invitations, désactivation, rôles, réinitialisation'],
        ['admin.journal', 'Journal des actions', 'Qui a fait quoi, quand'],
        ['admin.reprise', 'Reprise de GRR', 'Rapport de contrôle, attributions à valider'],
    ]);
@endphp
<ul class="grid min-w-0 grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3" data-cartes-admin>
    @foreach ($cartes as [$route, $titre, $detail])
        <li class="min-w-0"><a href="{{ route($route) }}" class="flex h-full min-h-11 flex-col gap-1 rounded-2xl border border-bord bg-white p-5 hover:border-sapin">
            <strong>{{ $titre }}</strong><span class="text-sm text-gris">{{ $detail }}</span></a></li>
    @endforeach
</ul>
@endsection

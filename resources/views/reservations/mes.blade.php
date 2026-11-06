@extends('layouts.app')
@section('titre', 'Mes réservations')
@section('bandeau')@include('partiels.bandeau')@endsection
@section('contenu')
@if ($errors->any())
    <div role="alert" class="rounded-lg border border-erreur bg-erreur-fond p-3 text-sm text-erreur">@foreach ($errors->all() as $e)<p>{{ $e }}</p>@endforeach</div>
@endif

<section class="flex flex-col gap-3" aria-labelledby="a-venir">
    <h2 id="a-venir" class="text-lg font-extrabold">À venir</h2>
    @forelse ($aVenir as $r)
        @include('reservations.ligne', ['r' => $r, 'actions' => true])
    @empty
        <p class="text-sm text-gris">Aucune réservation à venir. <a href="{{ route('reservations.formulaire') }}" class="underline">Réserver</a></p>
    @endforelse
</section>

<section class="flex flex-col gap-3" aria-labelledby="passees">
    <h2 id="passees" class="text-lg font-extrabold">Historique</h2>
    @forelse ($passees as $r)
        @include('reservations.ligne', ['r' => $r, 'actions' => false])
    @empty
        <p class="text-sm text-gris">Aucune réservation passée.</p>
    @endforelse
</section>
@endsection

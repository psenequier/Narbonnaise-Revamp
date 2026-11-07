@extends('layouts.app')
@section('titre', 'Webcams')
@section('large', '1')
@section('sans-titre', '1')
@php
    $lien = 'flex h-10 items-center rounded-[10px] border-[1.5px] border-sapin px-3.5 text-sm font-bold text-sapin no-underline';
    $heure = fn (string $iso) => \Carbon\Carbon::parse($iso)->timezone('Europe/Paris')->format('G \\h i');
@endphp
@section('contenu')
<div class="flex flex-wrap items-center justify-between gap-3">
    <div class="flex flex-col gap-0.5">
        <h1 class="text-2xl font-extrabold">Webcams</h1>
        <p class="text-sm text-gris">Dernière image de chaque webcam, relue toutes les 10 minutes.</p>
    </div>
    <a href="{{ $lienStation }}" target="_blank" rel="noopener noreferrer" class="flex h-11 items-center rounded-[10px] bg-sapin px-4 text-sm font-bold text-white no-underline" data-lien-station>Toutes les webcams de la station ↗</a>
</div>

<div class="flex flex-wrap gap-5">
    @foreach ($webcams as $w)
        <section class="flex min-w-0 flex-[1_1_420px] flex-col gap-3 rounded-2xl border border-bord bg-white p-5" aria-labelledby="webcam-{{ $w['cle'] }}" data-webcam="{{ $w['cle'] }}">
            <div class="flex flex-wrap items-baseline justify-between gap-1.5">
                <h2 id="webcam-{{ $w['cle'] }}" class="text-lg font-extrabold">{{ $w['nom'] }}</h2>
                <span class="text-sm text-gris">{{ $w['lieu'] }}</span>
            </div>
            @if ($w['image'] !== null && $w['heure'] !== null)
                <a href="{{ $w['page'] }}" target="_blank" rel="noopener noreferrer" aria-label="Ouvrir la webcam {{ $w['nom'] }} sur Skaping" class="block">
                    <img src="{{ route('webcams.image', ['cle' => $w['cle'], 'v' => \Carbon\Carbon::parse($w['heure'])->timestamp]) }}" alt="Dernière image de la webcam {{ $w['nom'] }}, {{ $heure($w['heure']) }}" width="640" height="360" class="h-auto w-full rounded-xl bg-fond">
                </a>
            @else
                <p role="status" class="flex aspect-video items-center justify-center rounded-xl bg-fond text-lg font-bold text-gris" data-webcam-indisponible>Webcam indisponible</p>
            @endif
            <div class="flex flex-wrap items-center justify-between gap-2.5">
                @if ($w['heure'] !== null)
                    @if ($w['ancienne'])
                        <span class="rounded-lg bg-alerte-fond px-2.5 py-1 text-sm font-bold text-alerte" data-image-ancienne>Image ancienne : {{ $heure($w['heure']) }}</span>
                    @else
                        <span class="text-sm text-gris" data-heure>Image de {{ $heure($w['heure']) }}</span>
                    @endif
                @else
                    <span></span>
                @endif
                <a href="{{ $w['page'] }}" target="_blank" rel="noopener noreferrer" class="{{ $lien }}">Panorama et archives (Skaping) ↗</a>
            </div>
        </section>
    @endforeach
</div>

<p class="text-sm italic text-gris" data-credit-webcams>Images : Skaping et station de Chamrousse.</p>
@endsection

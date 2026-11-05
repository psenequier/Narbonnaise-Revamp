<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titre') · Appartement Chamrousse</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @stack('tete')
</head>
<body class="min-h-screen bg-fond font-sans text-encre">
    @php($u = auth()->user())
    @php($actif = fn (string ...$routes) => request()->routeIs(...$routes) ? 'bg-sapin-clair font-bold text-sapin' : 'font-semibold text-texte-2 hover:bg-fond')
    <header class="border-b border-bord bg-white">
        <div class="mx-auto flex max-w-[1280px] flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-8">
            <a href="{{ route('calendrier') }}" class="flex items-center gap-2.5 text-encre no-underline">
                <svg width="30" height="30" viewBox="0 0 24 24" fill="none" stroke="#12433F" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 20l6-11 4 7 2-3 6 7z"/><path d="M8 11l1-2 1.5 2.5"/></svg>
                <span class="text-base font-extrabold">Appartement Chamrousse</span>
            </a>
            <nav aria-label="Navigation principale" class="flex flex-auto flex-wrap gap-1 text-sm">
                <a href="{{ route('calendrier') }}" class="flex min-h-11 items-center rounded-lg px-3.5 {{ $actif('calendrier', 'calendrier.mois', 'saison', 'jour') }}">Calendrier</a>
                <a href="{{ route('reservations.mes') }}" class="flex min-h-11 items-center rounded-lg px-3.5 {{ $actif('reservations.*') }}">Mes réservations</a>
                @can('admin')<a href="{{ route('admin.accueil') }}" class="flex min-h-11 items-center rounded-lg px-3.5 {{ $actif('admin.*') }}">Admin</a>@endcan
            </nav>
            <div class="flex items-center gap-3 text-sm">
                <a href="{{ route('profil') }}" aria-label="Mon profil" class="flex items-center gap-2.5 text-encre">
                    <span class="flex size-9 items-center justify-center rounded-full bg-sapin text-sm font-bold text-white" aria-hidden="true">{{ mb_strtoupper(mb_substr((string) $u?->prenom, 0, 1).mb_substr((string) $u?->nom, 0, 1)) }}</span>
                    <span class="font-semibold">{{ $u?->prenom }}</span>
                </a>
                <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="flex min-h-11 items-center text-gris underline-offset-4 hover:underline">Se déconnecter</button></form>
            </div>
        </div>
    </header>
    <main class="mx-auto flex {{ $__env->hasSection('large') ? 'max-w-[1280px]' : 'max-w-5xl' }} flex-col gap-6 px-4 py-6 sm:px-8">
        @unless ($__env->hasSection('sans-titre'))<h1 class="text-2xl font-extrabold">@yield('titre')</h1>@endunless
        @can('admin')
            @php($restants = app(\App\Domain\Vacances\SuiviAttributions::class)->restants())
            @if ($restants > 0)
                {{-- US-406-P2 : bandeau tant qu'il reste des vacances sans lignée. --}}
                <p role="status" class="rounded-lg border border-amber-400 bg-amber-50 p-3 text-sm" data-bandeau="attributions">
                    <strong>{{ $restants }} créneau(x) de vacances</strong> sans lignée.
                    <a href="{{ route('admin.vacances') }}" class="underline">Attribuer les vacances</a>
                </p>
            @endif
        @endcan
        {{-- Les statuts techniques de Fortify (« password-updated »…) sont traduits dans chaque page. --}}
        @if (session('status') && ! preg_match('/^[a-z-]+$/', (string) session('status')))
            <p role="status" class="rounded-lg bg-sapin-clair p-3 text-sm">{{ session('status') }}</p>
        @endif
        @yield('contenu')
    </main>
</body>
</html>

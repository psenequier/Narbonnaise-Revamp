<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titre') · Appartement Chamrousse</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-fond font-sans text-encre">
    <header class="border-b border-bord bg-white">
        <nav class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-4 px-4 py-1 text-sm" aria-label="Navigation principale">
            <a href="{{ route('calendrier') }}" class="mr-auto flex min-h-11 items-center font-extrabold">Appartement Chamrousse</a>
            <a href="{{ route('calendrier') }}" class="flex min-h-11 items-center underline-offset-4 hover:underline">Calendrier</a>
            <a href="{{ route('reservations.mes') }}" class="flex min-h-11 items-center underline-offset-4 hover:underline">Mes réservations</a>
            @can('admin')<a href="{{ route('admin.accueil') }}" class="flex min-h-11 items-center underline-offset-4 hover:underline">Admin</a>@endcan
            <a href="{{ route('profil') }}" class="flex min-h-11 items-center underline-offset-4 hover:underline">{{ auth()->user()->prenom }}</a>
            <form method="POST" action="{{ route('logout') }}">@csrf<button type="submit" class="flex min-h-11 items-center underline-offset-4 hover:underline">Se déconnecter</button></form>
        </nav>
    </header>
    <main class="mx-auto flex max-w-5xl flex-col gap-6 px-4 py-8">
        <h1 class="text-2xl font-extrabold">@yield('titre')</h1>
        {{-- Les statuts techniques de Fortify (« password-updated »…) sont traduits dans chaque page. --}}
        @if (session('status') && ! preg_match('/^[a-z-]+$/', (string) session('status')))
            <p role="status" class="rounded-lg bg-sapin-clair p-3 text-sm">{{ session('status') }}</p>
        @endif
        @yield('contenu')
    </main>
</body>
</html>

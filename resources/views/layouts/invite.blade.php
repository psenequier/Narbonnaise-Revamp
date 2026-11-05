<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titre') · Appartement Chamrousse</title>
    @vite(['resources/css/app.css'])
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
</head>
<body class="min-h-screen bg-fond font-sans text-encre">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-6 px-4 py-10">
        <div class="flex items-center gap-3">
            <img src="/favicon.svg" width="44" height="44" alt="" class="size-11">
            <div class="flex flex-col gap-0.5">
            <span class="font-display text-xl font-bold">Appartement Chamrousse</span>
            <span class="text-sm text-gris">Le Recoin · 1 650 m</span>
            </div>
        </div>
        <section class="flex flex-col gap-4 rounded-2xl border border-bord bg-white p-6">
            <h1 class="font-display text-2xl font-bold">@yield('titre')</h1>
            @if (session('status'))
                <p role="status" class="rounded-lg bg-sapin-clair p-3 text-sm">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <div role="alert" class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-900">
                    @foreach ($errors->all() as $erreur)<p>{{ $erreur }}</p>@endforeach
                </div>
            @endif
            @yield('contenu')
        </section>
    </main>
</body>
</html>

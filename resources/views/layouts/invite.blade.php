<!doctype html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('titre') · Appartement Chamrousse</title>
    @vite(['resources/css/app.css'])
</head>
<body class="min-h-screen bg-fond font-sans text-encre">
    <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center gap-6 px-4 py-10">
        <div class="flex flex-col gap-1">
            <span class="text-lg font-extrabold">Appartement Chamrousse</span>
            <span class="text-sm text-gris">Le Recoin · 1 650 m</span>
        </div>
        <section class="flex flex-col gap-4 rounded-2xl border border-bord bg-white p-6">
            <h1 class="text-2xl font-extrabold">@yield('titre')</h1>
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

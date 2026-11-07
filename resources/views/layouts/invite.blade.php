@php($ambiance = \App\Domain\Affichage\Ambiance::pour(now()))
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
<div class="grid min-h-screen md:grid-cols-2">
    {{-- Illustration de la charte Alpage : téléphériques animés (immobiles si l'appareil demande
         moins d'animations), version hiver du 1er octobre au 30 avril, été le reste de l'année. --}}
    <div class="ciel-{{ $ambiance->value }} relative flex h-[340px] flex-col gap-9 overflow-hidden px-5 py-5 md:h-auto md:min-h-[640px] md:px-14 md:py-12" data-ambiance="{{ $ambiance->value }}">
        <img src="/images/logo.svg" width="260" height="64" alt="Appartement Chamrousse" class="relative z-10 h-11 w-auto self-start md:h-14">
        <div class="relative z-10 hidden max-w-[480px] md:block">
            <p class="mb-3 text-[40px] leading-[1.15] font-extrabold text-principale">L'appartement de la famille, au Recoin.</p>
            <p class="text-lg">Réservez une journée ou une nuit et voyez qui monte.</p>
        </div>
        <img src="{{ asset($ambiance->illustration()) }}" alt="{{ $ambiance->description() }}" class="absolute inset-x-0 bottom-0 aspect-[640/460] w-full object-cover object-bottom md:aspect-auto md:h-[62%]">
    </div>
    <main class="flex items-start justify-center px-4 pb-8 md:items-center md:px-6 md:py-12">
        <section class="relative z-10 -mt-7 flex w-full max-w-[420px] flex-col gap-5 rounded-lg bg-white p-5 shadow-lg md:mt-0 md:p-9">
            <div>
                <h1 class="text-[26px] leading-tight font-bold md:text-[30px]">@yield('titre')</h1>
                @hasSection('sous-titre')<p class="mt-1 text-gris">@yield('sous-titre')</p>@endif
            </div>
            @if (session('status'))
                <p role="status" class="rounded-md bg-sapin-clair p-3 text-sm">{{ session('status') }}</p>
            @endif
            @if ($errors->any())
                <div role="alert" class="rounded-md bg-erreur-fond p-3 text-sm font-semibold text-erreur">
                    @foreach ($errors->all() as $erreur)<p>{{ $erreur }}</p>@endforeach
                </div>
            @endif
            @yield('contenu')
        </section>
    </main>
</div>
</body>
</html>

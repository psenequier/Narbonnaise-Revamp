{{-- US-405 : uniquement des lignées, jamais de nom de personne. --}}
<section class="rounded-2xl border border-bord bg-white p-5" aria-labelledby="cumul">
    <h2 id="cumul" class="mb-3 text-lg font-extrabold">Créneaux attribués par lignée, toutes années</h2>
    <table class="w-full text-sm">
        <thead><tr><th scope="col" class="text-left">Lignée</th><th scope="col" class="text-right">Créneaux</th></tr></thead>
        <tbody>
        @foreach ($cumul as $ligne)
            <tr data-lignee="{{ $ligne['lignee'] }}"><td class="py-1"><span class="mr-2 inline-block size-3 rounded-full align-middle" style="background: {{ $ligne['couleur'] }}"></span>{{ $ligne['lignee'] }}</td>
                <td class="text-right font-bold" data-total="{{ $ligne['total'] }}">{{ $ligne['total'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
</section>

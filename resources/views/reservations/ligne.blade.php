@php($premier = $r->creneaux->first())
@php($dernier = $r->creneaux->last())
<article class="flex flex-wrap items-center gap-3 rounded-2xl border border-bord bg-white p-4" data-reservation="{{ $r->id }}">
    <div class="mr-auto flex flex-col">
        <strong>Du {{ $premier?->date->format('d/m/Y') }} ({{ $premier?->type === 'N' ? 'nuit' : 'jour' }})
            au {{ $dernier?->date->format('d/m/Y') }} ({{ $dernier?->type === 'N' ? 'nuit' : 'jour' }})</strong>
        <span class="text-sm text-gris">
            {{ $r->mode === 'exclusive' ? 'Exclusive' : 'Ouverte' }} · {{ $r->creneaux->count() }} créneau(x)
            @if ($r->nombre_personnes) · {{ $r->nombre_personnes }} pers. @endif
            @if ($r->grr_id) · reprise de l'ancien site @endif
        </span>
    </div>
    @if ($actions)
        <a href="{{ route('reservations.modifier', $r) }}" class="flex min-h-11 items-center rounded-lg border border-bord px-4 font-bold">Modifier</a>
        <details class="relative">
            <summary class="flex min-h-11 cursor-pointer list-none items-center rounded-lg border border-red-300 px-4 font-bold text-red-900">Annuler</summary>
            {{-- US-306-P2 : confirmation avant l'annulation, sans boîte de dialogue JavaScript. --}}
            <form method="POST" action="{{ route('reservations.annuler', $r) }}" class="absolute right-0 z-10 mt-2 flex w-64 flex-col gap-2 rounded-lg border border-bord bg-white p-3 shadow">
                @csrf @method('DELETE')
                <p class="text-sm">Annuler cette réservation ? Les créneaux seront libérés.</p>
                <button type="submit" class="h-11 rounded-lg bg-red-800 font-bold text-white">Confirmer l'annulation</button>
            </form>
        </details>
    @endif
</article>

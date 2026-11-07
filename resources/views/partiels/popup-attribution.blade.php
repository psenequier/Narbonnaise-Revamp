{{-- Pop-up d'attribution (admins seulement, demande du 7 nov. 2026) : on attribue des vacances comme on
     réserve, par clics sur les créneaux « En attente » puis cette pop-up ; un clic sur un créneau attribué
     l'ouvre en édition (lignée, dates, retrait). Toutes les règles restent vérifiées par Attributions
     (période de vacances, chevauchement) ; une erreur revient sur la même vue. --}}
@can('admin')
@php($champ = 'h-11 rounded-[10px] border border-bord-fort bg-white px-2 text-sm font-medium')
<dialog id="popup-attribution" aria-labelledby="attribution-titre" class="m-auto w-full max-w-[480px] rounded-2xl p-0 text-encre shadow-2xl backdrop:bg-encre/45">
    <form method="POST" id="form-attribution" action="{{ route('admin.plages.attribuer') }}" data-action-creation="{{ route('admin.plages.attribuer') }}" class="flex flex-col gap-4 p-6 pb-3">
        @csrf
        {{-- Édition d'une plage : le script active ce champ (PUT) et change l'adresse du formulaire. --}}
        <input type="hidden" name="_method" value="PUT" disabled data-methode>
        <h2 id="attribution-titre" class="text-xl font-extrabold" data-titre>Nouvelle attribution</h2>
        <div class="rounded-[10px] bg-fond px-3.5 py-3 text-sm leading-relaxed"><b data-periode></b><br><span data-compte></span></div>
        <fieldset class="flex flex-col gap-1.5">
            <legend class="mb-1.5 text-sm font-bold">Lignée</legend>
            @foreach ($lignees as $l)
                <label class="flex cursor-pointer items-center gap-2 rounded-[10px] border border-bord-fort p-2.5 text-sm font-semibold">
                    <input type="radio" name="lignee_id" value="{{ $l->id }}" required @checked($loop->first)>
                    <span class="case case-attribue min-h-0 w-8 items-center px-0 text-center" aria-hidden="true">{{ $symboles[$l->nom] ?? '' }}</span>
                    {{ $l->nom }}
                </label>
            @endforeach
        </fieldset>
        {{-- Dates : posées par la sélection ; modifiables à la main en édition. --}}
        <details data-dates hidden>
            <summary class="cursor-pointer text-sm font-bold text-sapin underline">Changer les dates</summary>
            <div class="mt-2 grid grid-cols-[minmax(0,1fr)_auto] gap-2">
                <label class="flex flex-col text-xs font-bold">Du<input type="date" name="debut" required class="{{ $champ }}"></label>
                <label class="flex flex-col text-xs font-bold">&nbsp;<select name="debut_type" class="{{ $champ }}"><option value="N">nuit</option><option value="J">journée</option></select></label>
                <label class="flex flex-col text-xs font-bold">Au<input type="date" name="fin" required class="{{ $champ }}"></label>
                <label class="flex flex-col text-xs font-bold">&nbsp;<select name="fin_type" class="{{ $champ }}"><option value="J">journée</option><option value="N">nuit</option></select></label>
            </div>
        </details>
        <p class="text-xs font-medium text-gris">Les membres sont prévenus par e-mail de chaque attribution, modification ou retrait.</p>
    </form>
    <div class="flex flex-nowrap items-start justify-end gap-1.5 px-6 pb-6">
        <details hidden data-zone-retrait class="relative mr-auto">
            <summary class="flex h-11 cursor-pointer list-none items-center rounded-full border border-erreur px-3 text-[14px] font-bold whitespace-nowrap text-erreur"><span class="sm:hidden">Retirer</span><span class="hidden sm:inline">Retirer l'attribution</span></summary>
            <form method="POST" action="" data-form-retrait class="absolute bottom-full left-0 z-10 mb-2 flex w-64 max-w-[calc(100vw-2rem)] flex-col gap-2 rounded-lg border border-bord bg-white p-3 shadow">
                @csrf @method('DELETE')
                <p class="text-sm">Retirer cette attribution ? Ses créneaux repassent en attente d'attribution.</p>
                <button type="submit" class="h-11 rounded-lg bg-erreur font-bold text-white">Confirmer le retrait</button>
            </form>
        </details>
        <button type="button" data-fermer class="h-11 shrink-0 rounded-full border border-bord-fort bg-white px-3 text-[14px] font-bold">Fermer</button>
        <button type="submit" form="form-attribution" class="h-11 shrink-0 rounded-full bg-sapin px-3 text-[14px] font-bold text-white" data-valider>Attribuer</button>
    </div>
</dialog>
@endcan

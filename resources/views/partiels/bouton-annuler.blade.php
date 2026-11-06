{{-- Bouton « Annuler » de « Mes réservations », repris dans la pop-up et l'écran de modification.
     La confirmation s'ouvre à droite du bouton, alignée sur son bas (au-dessus sur téléphone) : visible sans défiler.
     US-306-P2 : confirmation avant l'annulation, sans boîte de dialogue JavaScript. --}}
<details class="relative" data-annulation>
    <summary class="flex {{ $hauteur ?? 'min-h-11' }} cursor-pointer list-none items-center rounded-lg border border-erreur px-4 font-bold whitespace-nowrap text-erreur">Annuler la réservation</summary>
    <form method="POST" action="{{ $action }}" class="absolute bottom-full left-0 z-10 mb-2 flex w-64 max-w-[calc(100vw-2rem)] {{ ($auDessus ?? false) ? '' : 'sm:bottom-0 sm:left-full sm:mb-0 sm:ml-2' }} flex-col gap-2 rounded-lg border border-bord bg-white p-3 shadow" data-form-annulation>
        @csrf @method('DELETE')
        @isset($retour)<input type="hidden" name="retour" value="{{ $retour }}">@endisset
        <p class="text-sm">Annuler cette réservation ? Les créneaux seront libérés.</p>
        <button type="submit" class="h-11 rounded-lg bg-erreur font-bold text-white">Confirmer l'annulation</button>
    </form>
</details>

{{-- Pop-up de réservation ouverte par la sélection par clics (resources/js/calendrier.js).
     Elle envoie le même formulaire que la page « Réserver » : toutes les règles restent vérifiées
     par ServiceReservation ; une erreur revient sur le calendrier. --}}
{{-- Refus du serveur : la pop-up se rouvre sur la même période et affiche la raison. --}}
<dialog id="popup-reservation" @if ($errors->any() && is_array(old('creneaux'))) data-anciens="{{ implode(',', array_filter(old('creneaux'), 'is_string')) }}" @endif aria-labelledby="popup-titre" class="m-auto w-full max-w-[480px] rounded-2xl p-0 text-encre shadow-2xl backdrop:bg-encre/45">
    <form method="POST" action="{{ route('reservations.enregistrer') }}" class="flex flex-col gap-4 p-6">
        @csrf
        {{-- Après « Réserver », on reste sur la vue du calendrier ouverte (décision du 5 nov. 2026). --}}
        <input type="hidden" name="retour" value="{{ '/'.ltrim(request()->path(), '/') }}">
        {{-- Édition d'une de mes réservations : le script active ce champ (PUT) et change l'adresse du formulaire. --}}
        <input type="hidden" name="_method" value="PUT" disabled data-methode>
        <input type="hidden" name="debut" value="">
        <input type="hidden" name="fin" value="">
        <div data-creneaux hidden></div>
        <h2 id="popup-titre" class="text-xl font-extrabold" data-titre>Nouvelle réservation</h2>
        @if ($errors->any())
            <div role="alert" class="rounded-[10px] border border-erreur bg-erreur-fond px-3 py-2.5 text-sm text-erreur">@foreach ($errors->all() as $erreur)<p>{{ $erreur }}</p>@endforeach</div>
        @endif
        <div class="rounded-[10px] bg-fond px-3.5 py-3 text-sm leading-relaxed"><b data-periode></b><br><span data-compte></span></div>
        <fieldset class="grid grid-cols-2 gap-2">
            <legend class="sr-only">Type de réservation</legend>
            <label class="flex cursor-pointer items-start gap-2 rounded-[10px] border border-bord-fort p-2.5 text-sm font-semibold">
                <input type="radio" name="mode" value="ouverte" checked>
                <span>Ouverte<small class="block font-medium text-gris">Réservation habituelle</small></span>
            </label>
            <label class="flex cursor-pointer items-start gap-2 rounded-[10px] border border-bord-fort p-2.5 text-sm font-semibold">
                <input type="radio" name="mode" value="exclusive" @checked(old('mode') === 'exclusive')>
                <span>Exclusive<small class="block font-medium text-gris">L'appartement est à vous seuls</small></span>
            </label>
        </fieldset>
        <label class="flex flex-col gap-1.5 text-sm font-bold">Nombre de personnes
            <input type="number" name="nombre_personnes" min="1" max="{{ \App\Domain\Reservations\ServiceReservation::PERSONNES_MAX }}" value="{{ old('nombre_personnes', 2) }}" required class="max-w-[120px] rounded-[10px] border border-bord-fort px-3 py-2.5 text-sm font-medium">
        </label>
        <label class="flex flex-col gap-1.5 text-sm font-bold">Commentaire
            <textarea name="commentaire" rows="3" maxlength="200" placeholder="Ex. : arrivée tard le vendredi soir" class="rounded-[10px] border border-bord-fort px-3 py-2.5 text-sm font-medium">{{ old('commentaire') }}</textarea>
            <span class="text-xs font-medium text-gris">Affiché dans le calendrier, coupé par « … » s'il est trop long. 200 caractères au plus.</span>
        </label>
        <label class="flex flex-col gap-1.5 text-sm font-bold">Invités extérieurs (facultatif)
            <input type="text" name="invites" value="{{ old('invites') }}" maxlength="500" placeholder="Noms des invités hors famille" class="rounded-[10px] border border-bord-fort px-3 py-2.5 text-sm font-medium">
        </label>
        <a href="#" hidden data-lien-dates class="text-sm font-bold text-sapin underline">Changer les dates ou annuler la réservation</a>
        <div class="flex justify-end gap-2">
            <button type="button" data-fermer class="h-11 rounded-full border border-bord-fort bg-white px-[18px] text-sm font-bold">Annuler</button>
            <button type="submit" class="h-11 rounded-full bg-sapin px-[18px] text-sm font-bold text-white" data-valider>Réserver</button>
        </div>
    </form>
</dialog>

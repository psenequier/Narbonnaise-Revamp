@extends('layouts.invite')
{{-- US-1001-P2 : le nom est celui que l'application déclare ; seul le domaine de retour est vérifié. --}}
@section('titre', 'Autoriser '.$client->nom.' à accéder à votre compte ?')
@section('sous-titre', 'Nom déclaré par l\'application, qui revient ensuite vers '.$client->domaine.'. Si ce n\'est pas vous, cliquez sur « Refuser ».')
@section('contenu')
<form method="POST" action="{{ route('assistants.decider') }}" class="flex flex-col gap-4" data-autorisation>
    @csrf
    @foreach ($champs as $nom => $valeur)
        <input type="hidden" name="{{ $nom }}" value="{{ $valeur }}">
    @endforeach
    <fieldset class="flex flex-col gap-3 rounded-md border border-bord p-4">
        <legend class="px-1 font-extrabold">Ce que l'assistant pourra faire</legend>
        <label class="flex items-start gap-3">
            <input type="checkbox" checked disabled class="mt-1 size-5 accent-sapin">
            <span><strong>Consulter le calendrier et les statistiques</strong><br>
                <span class="text-sm text-gris">Nuits libres et réservées, qui vient et à combien, vos réservations, chiffres de la page Statistiques. Toujours inclus.</span></span>
        </label>
        <label class="flex items-start gap-3">
            <input type="checkbox" name="reservation" value="1" class="mt-1 size-5 accent-sapin">
            <span><strong>Réserver, modifier et annuler en mon nom</strong><br>
                <span class="text-sm text-gris">Mêmes règles que sur le site. L'assistant vous demandera de confirmer chaque action ; vous recevrez l'e-mail de confirmation habituel.</span></span>
        </label>
    </fieldset>
    <p class="rounded-md bg-info-fond p-3 text-sm">Jamais transmis : adresses e-mail, commentaires des autres membres, espace admin. Les noms des membres qui réservent sont envoyés à l'assistant que vous avez choisi. L'accès expire 14 mois après votre autorisation ; vous pouvez le retirer à tout moment dans Mon profil.</p>
    <div class="flex justify-end gap-3">
        <button type="submit" name="decision" value="refuser" class="h-11 rounded-lg border border-sapin px-5 font-bold text-sapin">Refuser</button>
        <button type="submit" name="decision" value="autoriser" class="h-11 rounded-lg bg-sapin px-6 font-bold text-white">Autoriser</button>
    </div>
</form>
@endsection

@component('emails._cadre')
<p style="margin:0 0 12px">{{ $d['prenom'] }} demande la suppression de son compte.</p>
<p style="margin:0 0 12px">Une fois confirmée depuis sa fiche, seul son prénom est conservé : nom, adresse e-mail, mot de passe et compte Google lié sont effacés, y compris du journal. Ses réservations passées restent, avec son prénom.</p>
<p style="margin:16px 0 0"><a href="{{ $d['lien'] }}">Ouvrir sa fiche</a></p>
@endcomponent

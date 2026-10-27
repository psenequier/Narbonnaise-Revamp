@component('emails._cadre')
<p style="margin:0 0 12px">Bonjour {{ $d['prenom'] }},</p>
<p style="margin:0 0 12px">Le nouveau site de réservation de l'appartement est en service. Pour des raisons de sécurité, votre ancien mot de passe n'a pas pu être repris : choisissez-en un nouveau avec le lien ci-dessous, valable 7 jours.</p>
<p style="margin:0 0 12px">Vous vous connecterez ensuite avec votre adresse e-mail : <strong>{{ $d['email'] }}</strong>.</p>
<p style="margin:16px 0 0"><a href="{{ $d['lien'] }}" style="color:#12433F;font-weight:700">Choisir mon mot de passe</a></p>
@endcomponent

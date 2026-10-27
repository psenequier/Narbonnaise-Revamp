@component('emails._cadre')
<p style="margin:0 0 12px">L'année <strong>{{ $d['annee'] }}</strong> est ouverte à la réservation.</p>
<p style="margin:0 0 4px">Vacances bloquées, attribuées aux lignées par les admins :</p>
<ul style="padding-left:20px">
@foreach ($d['periodes'] as $p)<li>{{ $p }}</li>@endforeach
</ul>
<p style="margin:16px 0 0"><a href="{{ $d['lien'] }}" style="color:#12433F;font-weight:700">Voir le calendrier</a> (connexion requise)</p>
@endcomponent

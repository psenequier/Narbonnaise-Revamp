@component('emails._cadre')
@php($annees = $d['annees'] ?? [['annee' => $d['annee'], 'periodes' => $d['periodes']]])
@if (count($annees) > 1)
<p style="margin:0 0 12px">Les années <strong>{{ $d['annee'] }}</strong> sont ouvertes à la réservation.</p>
@else
<p style="margin:0 0 12px">L'année <strong>{{ $annees[0]['annee'] }}</strong> est ouverte à la réservation.</p>
@endif
@foreach ($annees as $a)
<p style="margin:0 0 4px">{{ count($annees) > 1 ? $a['annee'].' : vacances' : 'Vacances' }} bloquées, attribuées aux lignées par les admins :</p>
<ul style="padding-left:20px">
@foreach ($a['periodes'] as $p)<li>{{ $p }}</li>@endforeach
</ul>
@endforeach
<p style="margin:16px 0 0"><a href="{{ $d['lien'] }}" style="color:#12433F;font-weight:700">Voir le calendrier</a> (connexion requise)</p>
@endcomponent

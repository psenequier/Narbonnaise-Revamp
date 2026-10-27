@component('emails._cadre')
<p style="margin:0 0 12px">
    @switch($d['evenement'])
        @case('retrait') Une plage de vacances a été retirée. @break
        @case('modification') Une plage de vacances a été modifiée. @break
        @default Des vacances ont été attribuées.
    @endswitch
    Voici la répartition à jour pour {{ $d['annee'] }} :
</p>
@if ($d['plages'] === [])
    <p>Aucune plage attribuée pour l'instant.</p>
@else
<table style="border-collapse:collapse;width:100%;font-size:14px">
    <tr><th align="left">Vacances</th><th align="left">Du</th><th align="left">Au</th><th align="left">Lignée</th></tr>
    @foreach ($d['plages'] as $p)
        <tr><td>{{ $p['periode'] }}</td><td>{{ $p['du'] }}</td><td>{{ $p['au'] }}</td><td><strong>{{ $p['lignee'] }}</strong> ({{ $p['creneaux'] }} créneaux)</td></tr>
    @endforeach
</table>
@endif
<p style="margin:16px 0 0"><a href="{{ $d['lien'] }}" style="color:#12433F;font-weight:700">Voir le calendrier</a> (connexion requise)</p>
@endcomponent

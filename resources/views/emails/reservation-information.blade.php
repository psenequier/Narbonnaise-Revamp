@component('emails._cadre')
<p style="margin:0 0 12px">
    @if ($d['action'] === 'annulee')
        <strong>{{ $d['reservant'] }}</strong> a annulé une réservation : des créneaux se libèrent.
    @else
        <strong>{{ $d['reservant'] }}</strong> a réservé l'appartement.
    @endif
</p>
@include('emails._reservation')
@endcomponent

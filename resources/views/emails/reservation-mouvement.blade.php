@component('emails._cadre')
<p style="margin:0 0 12px"><strong>{{ $d['reservant'] }}</strong> a {{ $d['action'] }} une réservation sur des créneaux où vous êtes inscrit.</p>
@include('emails._reservation')
@endcomponent

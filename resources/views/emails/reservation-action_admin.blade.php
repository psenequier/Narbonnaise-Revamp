@component('emails._cadre')
<p style="margin:0 0 12px"><strong>{{ $d['admin'] }}</strong>, admin du site, a {{ $d['action'] }} votre réservation.</p>
<p style="margin:0 0 12px">Motif : {{ $d['motif'] }}</p>
@include('emails._reservation')
@endcomponent

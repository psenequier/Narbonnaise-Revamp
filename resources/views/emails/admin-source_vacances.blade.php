@component('emails._cadre')
<p style="margin:0 0 12px">{{ $d['intro'] }}</p>
@if (! empty($d['lignes']))
<ul style="padding-left:20px">
@foreach ($d['lignes'] as $ligne)<li>{{ $ligne }}</li>@endforeach
</ul>
@endif
<p style="margin:16px 0 0"><a href="{{ $d['lien'] }}" style="color:#12433F;font-weight:700">Ouvrir l'écran Vacances</a> (connexion requise)</p>
@endcomponent

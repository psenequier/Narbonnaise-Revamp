<p style="margin:0 0 8px"><strong>{{ $d['periode'] }}</strong></p>
<ul style="margin:0 0 12px;padding-left:20px">
    @foreach ($d['creneaux'] as $creneau)<li>{{ $creneau }}</li>@endforeach
</ul>
<p style="margin:0 0 4px">Type : {{ $d['mode'] }}</p>
@if ($d['nombre_personnes'])<p style="margin:0 0 4px">Personnes : {{ $d['nombre_personnes'] }}</p>@endif
@if (! empty($d['commentaire']))<p style="margin:0 0 4px">Commentaire : {{ $d['commentaire'] }}</p>@endif
<p style="margin:16px 0 0"><a href="{{ $d['lien'] }}" style="color:#12433F;font-weight:700">Voir le jour sur le site</a> (connexion requise)</p>

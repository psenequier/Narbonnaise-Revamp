@component('emails._cadre')
<p style="margin:0 0 12px">{{ $d['intro'] }}</p>
@if (! empty($d['lignes']))
<ul style="padding-left:20px">
@foreach ($d['lignes'] as $ligne)<li>{{ $ligne }}</li>@endforeach
</ul>
@endif
<p style="margin:16px 0 0">Le détail technique est dans le journal applicatif (storage/logs) de l'hébergement.</p>
@endcomponent

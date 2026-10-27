@component('emails._cadre')
<p style="margin:0 0 12px">Le contrôle quotidien a trouvé des créneaux incohérents. Rien n'a été corrigé automatiquement.</p>
<ul style="padding-left:20px">
@foreach ($d['anomalies'] as $a)<li>{{ $a }}</li>@endforeach
</ul>
@endcomponent

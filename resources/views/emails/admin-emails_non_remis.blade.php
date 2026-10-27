@component('emails._cadre')
<p style="margin:0 0 12px">Ces e-mails n'ont pas pu partir depuis plus de 24 heures. Les relances continuent ; vérifiez la boîte e-mail du MX Plan.</p>
<ul style="padding-left:20px">
@foreach ($d['emails'] as $e)
    <li>n° {{ $e['id'] }} · {{ $e['gabarit'] }} · depuis {{ $e['depuis'] }} · {{ $e['tentatives'] }} tentative(s) · {{ $e['erreur'] }}</li>
@endforeach
</ul>
@endcomponent

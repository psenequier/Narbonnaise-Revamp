@component('emails._cadre')
<p style="margin:0 0 12px">Bonjour,</p>
<p style="margin:0 0 12px">Le site de réservation de l'appartement de Chamrousse change : il sera remplacé par un nouveau site le <strong>{{ $d['date'] }}</strong>. Le site sera indisponible pendant <strong>{{ $d['duree'] }}</strong>.</p>
<p style="margin:0 0 12px">Vos réservations, l'historique et les attributions de vacances sont repris. À partir de la bascule, <strong>vous vous connecterez avec votre adresse e-mail</strong> (celle qui reçoit ce message) au lieu de votre identifiant actuel.</p>
<p style="margin:0">Le jour J, vous recevrez un e-mail avec l'adresse du nouveau site.</p>
@endcomponent

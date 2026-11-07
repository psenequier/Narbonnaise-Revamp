{{-- Texte de l'annonce (US-806), partagé par l'e-mail et l'aperçu de l'écran admin. --}}
@php
    $s = $d['souvenirs'] ?? null;
    $nombre = fn (int $n) => number_format($n, 0, ',', "\u{202F}");
@endphp
<p style="margin:0 0 12px">Bonjour,</p>
<p style="margin:0 0 12px">Le site de réservation de l'appartement de Chamrousse change : il sera remplacé par un nouveau site le <strong>{{ $d['date'] }}</strong>. Le site sera indisponible pendant <strong>{{ $d['duree'] }}</strong>.</p>
<p style="margin:0 0 12px">Vos réservations, l'historique et les attributions de vacances sont repris. À partir de la bascule, <strong>vous vous connecterez avec votre adresse e-mail</strong> (celle qui reçoit ce message) au lieu de votre identifiant actuel.</p>
<p style="margin:0 0 12px">Le jour J, vous recevrez un e-mail avec l'adresse du nouveau site.</p>
<p style="margin:0 0 12px">Avant de tourner la page, un petit hommage.
@if ($s !== null)
    Le site actuel nous accompagne depuis <strong>{{ $s['depuis'] }}</strong>{{ $s['ans'] >= 1 ? ', soit plus de '.$s['ans'].($s['ans'] > 1 ? ' ans' : ' an') : '' }}.
    Pendant tout ce temps, il a enregistré <strong>{{ $nombre($s['reservations']) }} réservations</strong>, soit <strong>{{ $nombre($s['creneaux']) }} créneaux réservés</strong> en tout, dont {{ $nombre($s['nuits']) }} nuits là-haut, pour {{ $nombre($s['membres']) }} membres de la famille.
@else
    Le site actuel nous a accompagnés pendant de longues années de week-ends et de vacances à la montagne.
@endif
    Il a tenu bon, survécu à au moins une mise à jour majeure{{ ($d['mise_a_jour'] ?? '') !== '' ? ' (en '.$d['mise_a_jour'].')' : '' }}, et n'a jamais protesté devant un mois de février complet.</p>
<p style="margin:0">Il prend maintenant une retraite bien méritée. Place à une version sur mesure, faite maison par <a href="https://www.youtube.com/shorts/ddW4i7Oftkc" style="color:#1F4D3A;font-weight:700">le Glaude</a> : pas de soupe aux choux, promis, mais des réservations aux petits oignons. Merci au vieux site pour ses bons et loyaux services, et à très bientôt là-haut !</p>

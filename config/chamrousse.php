<?php

// Réglages propres au site. Les valeurs réelles vivent dans le .env de
// production (partage/.env), jamais dans le dépôt public.
return [
    // « Nom:#couleur,Nom:#couleur,… » ; vide = noms fictifs (développement, CI).
    'lignees' => env('LIGNEES', ''),
];

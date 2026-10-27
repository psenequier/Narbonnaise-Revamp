<?php

// Réglages propres au site. Les valeurs réelles vivent dans le .env de
// production (partage/.env), jamais dans le dépôt public.
return [
    // « Nom:#couleur,Nom:#couleur,… » ; vide = noms fictifs (développement, CI).
    'lignees' => env('LIGNEES', ''),

    // Reprise de GRR (ADR 014) : préfixe des tables GRR dans la même base (« NAR_ » en production).
    'grr_prefixe' => env('GRR_PREFIXE', 'grr_'),
    // Décisions de la famille, propres à la production (logins GRR séparés par des virgules) :
    // comptes promus admins, et comptes repris désactivés (compte partagé, par exemple).
    'grr_admins' => env('GRR_ADMINS', ''),
    'grr_desactives' => env('GRR_DESACTIVES', ''),
];

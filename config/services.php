<?php

return [
    // Portail des API de Météo-France, bulletin d'avalanche (US-708) : gratuit, clé personnelle.
    'meteofrance' => [
        'cle' => env('METEOFRANCE_CLE'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],
];

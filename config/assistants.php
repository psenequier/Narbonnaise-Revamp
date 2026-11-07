<?php

// Assistant IA connecté par le protocole MCP (épique 10, ADR 020).
// Les durées sont des réglages du serveur (partage/.env), avec ces valeurs par défaut.
return [
    // Jeton d'accès : 1 heure (US-1001-P4).
    'jeton_minutes' => (int) env('MCP_JETON_MINUTES', 60),
    // Un accès expire 14 mois après le clic sur « Autoriser », qu'il serve ou non
    // (décision de Pascal du 7 nov. 2026 : pas d'expiration après une période sans utilisation).
    'duree_max_mois' => (int) env('MCP_DUREE_MAX_MOIS', 14),
    // Limites par membre (US-1003-N3, US-1004-N4).
    'appels_par_minute' => (int) env('MCP_APPELS_PAR_MINUTE', 60),
    'actions_par_minute' => (int) env('MCP_ACTIONS_PAR_MINUTE', 30),
    // Un code d'autorisation ne sert qu'une fois, dans les 10 minutes.
    'code_minutes' => 10,
    // Période maximale demandée à l'outil « calendrier » (US-1003-N2).
    'jours_max' => 400,
];

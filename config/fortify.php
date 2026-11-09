<?php

use Laravel\Fortify\Features;

// Pas d'inscription libre : seuls les admins créent les comptes (PRD, US-104).
return [
    'guard' => 'web',
    'passwords' => 'users',
    'username' => 'email',
    'email' => 'email',
    'lowercase_usernames' => true,
    'home' => '/',
    'prefix' => '',
    'domain' => null,
    'middleware' => ['web'],
    'limiters' => [
        'login' => 'login',
    ],
    'views' => true,
    'features' => [
        Features::resetPasswords(),
        Features::updateProfileInformation(),
        Features::updatePasswords(),
        // Double authentification supprimée pour tous (décision de Pascal du 7 nov. 2026, ADR 018).
    ],
];

<?php

declare(strict_types=1);

/*
 * Complète la configuration d'e-mail de Laravel (les autres clés gardent leurs valeurs
 * par défaut, lues dans .env).
 *
 * Garde-fou pour les tests avec une copie de la vraie base : si MAIL_REDIRIGER_VERS est
 * renseigné, TOUS les e-mails (boîte d'envoi, notifications, mot de passe oublié) partent
 * vers cette seule adresse, et les copies et copies cachées sont supprimées. Vide en
 * production après la bascule.
 */
$redirection = trim((string) env('MAIL_REDIRIGER_VERS', ''));

return [
    'to' => $redirection === '' ? null : ['address' => $redirection, 'name' => 'Test (e-mail redirigé)'],
];

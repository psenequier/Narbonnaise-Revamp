<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// expose_php peut être actif chez l'hébergeur : on retire l'en-tête (ZAP 10037).
header_remove('X-Powered-By');

// Maintenance pendant un déploiement (deploiement/deployer.sh) : seule la
// route de migration reste accessible, elle est protégée par son jeton.
$down = __DIR__.'/../../partage/storage/framework/down';
if (is_file($down) && ($_SERVER['REQUEST_URI'] ?? '') !== '/_deploiement/migrer') {
    http_response_code(503);
    header('Retry-After: 120');
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Mise à jour</title><p>Le site est en cours de mise à jour. Réessayez dans deux minutes.</p>';
    exit;
}

require __DIR__.'/../vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$app->handleRequest(Request::capture());

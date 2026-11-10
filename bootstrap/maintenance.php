<?php

declare(strict_types=1);

/**
 * Maintenance pendant un déploiement (deploiement/deployer.sh) : seule la route de
 * migration reste accessible, protégée par son jeton. On compare le chemin seul :
 * les nuits de répétition et de bascule l'appellent avec « ?reprise=1 ».
 */
function chamrousse_bloque_par_maintenance(string $fichierDown, string $requestUri): bool
{
    if (! is_file($fichierDown)) {
        return false;
    }

    $chemin = parse_url($requestUri, PHP_URL_PATH);

    return $chemin !== '/_deploiement/migrer';
}

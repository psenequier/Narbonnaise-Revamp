<?php

// Réglages propres au site. Les valeurs réelles vivent dans le .env de
// production (partage/.env), jamais dans le dépôt public.
return [
    // « Nom:#couleur,Nom:#couleur,… » ; vide = noms fictifs (développement, CI).
    'lignees' => env('LIGNEES', ''),
    // Autres mots qui désignent une lignée dans les libellés GRR : « mot:Nom de la lignée,… ».
    'lignees_alias' => env('LIGNEES_ALIAS', ''),

    // Reprise de GRR (ADR 014) : préfixe des tables GRR dans la même base (« NAR_ » en production).
    'grr_prefixe' => env('GRR_PREFIXE', 'grr_'),

    // Ajustements appliqués à chaque import, par login GRR : « login:admin,login:membre,login:desactive ».
    // Exemple d'usage : promouvoir un membre admin, désactiver un compte partagé.
    'grr_ajustements' => env('GRR_AJUSTEMENTS', ''),

    // « ouverte » : le bouton « Lancer la reprise » est disponible pour les admins.
    // À passer à « fermee » après la bascule : un nouvel import écraserait les noms et rôles modifiés depuis.
    'grr_reprise' => env('GRR_REPRISE', 'ouverte'),

    // Annonce de la bascule (US-806) : date de la mise à jour majeure de GRR, en clair
    // (« 2019 », « mars 2019 »). Vide : l'annonce la mentionne sans date.
    'grr_mise_a_jour_majeure' => env('GRR_MISE_A_JOUR_MAJEURE', ''),

    // Bascule (US-805) : sauvegarde complète et export GRR, chiffrés en AES-256 avec ce mot de passe
    // (le même que les sauvegardes quotidiennes). Vide ou trop court : la bascule s'arrête.
    'export_mot_de_passe' => env('BACKUP_ARCHIVE_PASSWORD', ''),
    // Dossier des archives, hors du dossier publié ; vide = storage/app/exports.
    'dossier_exports' => env('EXPORTS_DOSSIER', ''),
];

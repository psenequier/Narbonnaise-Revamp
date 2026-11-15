# Répétitions et bascule de GRR vers le nouveau site

Procédure des nuits de répétition (vers le 23 novembre et le 9 décembre 2026) et de la
bascule (12-13 décembre). L'offre OVH Starter n'a qu'un site, et OVH refuse de changer son
dossier racine : on remplace le dossier `www` par un lien vers le nouveau site, en SFTP, et le
retour arrière remet `www` en place. GRR et ses tables `NAR_*` ne sont jamais modifiés par le
nouveau site.

Première répétition réussie de bout en bout le 15 novembre 2026 (environ 1 h 15, comptes,
réservations et attributions repris sans rejet). Les leçons de cette nuit et des essais ratés
des 10 et 11 novembre sont dans « Pièges vus en répétition », en bas de page.

> Dépôt public : aucune valeur réelle ici. Les logins, noms de lignées et secrets vivent
> dans `partage/.env` sur le serveur et dans les secrets GitHub.

## Une fois, avant la première répétition (tâche T09)

1. SFTP activé, clé de déploiement, secrets de l’environnement « production » (`SFTP_*`, `SITE_URL`), variable `DOSSIER_DISTANT`.
2. `partage/.env` sur le serveur, en plus des réglages de base :
   - `GRR_PREFIXE=NAR_`
   - `GRR_AJUSTEMENTS=<login GRR de chaque admin>:admin,…,<login du compte partagé>:desactive`
     (le **login GRR**, pas l'adresse e-mail ; appliqué à chaque reprise, répétitions comprises)
   - `GRR_REPRISE=ouverte`
   - `LIGNEES="<Nom>:#couleur,<Nom>:#couleur,<Nom>:#couleur"` (les trois lignées réelles) : lu au
     premier déploiement seulement, quand aucune lignée n'existe ; ensuite, le nom se modifie
     dans Admin → Lignées (la couleur n'est plus affichée : une seule couleur pour les créneaux
     attribués, décision du 7 nov. 2026)
   - `LIGNEES_ALIAS="<surnom>:<Nom de la lignée>,…"` : autres mots des libellés GRR qui désignent une
     lignée, pour que la reprise attribue d'elle-même les anciennes vacances (le premier mot du nom
     est déjà reconnu, sans tenir compte de la casse)
   - `MAIL_REDIRIGER_VERS=<adresse de Pascal>` : pendant les répétitions, tous les e-mails du
     nouveau site (rappels, mot de passe oublié, informations groupées) partent vers cette seule
     adresse, jamais vers les membres. Voir « E-mails » ci-dessous avant de le vider. **À vérifier
     sur le serveur avant chaque répétition** : le 15 novembre, la copie locale de `.env` l'avait
     vide. Une fois le site servi, l'écran Admin → E-mails affiche la redirection en vigueur et
     permet de la changer sans toucher au fichier.
3. Variables du dépôt (Settings → Secrets and variables → Actions → Variables) :
   `DEPLOIEMENT_ACTIF=oui`, `SITE_SERVI=non`, `REPRISE_GRR=non`.

## Nuit de répétition (ou de bascule) — US-805

| Heure | Action | Qui |
|---|---|---|
| 22 h 00 | Prévenir les membres : site indisponible jusqu'à minuit | admin |
| 22 h 05 | En SFTP (`sftp -i ~/.ssh/<clé de déploiement> <utilisateur>@<hôte SFTP OVH>`) : `rename www www-grr` puis `symlink narbonnaise-v2/actuelle/public www`, **avant** d'approuver le déploiement (sinon le contrôle de fin tombe sur GRR, erreur 404, et il faut relancer le job). GRR n'est plus servi : plus aucune réservation possible (N3). Retour : `rm www` puis `rename www-grr www` | Pascal |
| 22 h 10 | Variables du dépôt : `SITE_SERVI=oui`, `REPRISE_GRR=oui` | Pascal |
| 22 h 12 | Actions → ci → Run workflow sur `main` (ou fusion d'une PR), approuver l'environnement « production ». Si ce contenu exact a déjà passé toute la CI (PR à jour de main, ou passage précédent), les tests sont sautés et le déploiement attend l'approbation tout de suite | Pascal |
| 22 h 15 | Le job enchaîne, et s'arrête à la première erreur (P1) : maintenance (posée avant l'envoi, dès que `SITE_SERVI` et `REPRISE_GRR` valent `oui`), envoi, **sauvegarde complète** puis **export GRR (SQL et CSV)**, chiffrés en AES-256 et relus, migrations, import, contrôle, puis **ouverture de l'année scolaire** (dates officielles de Noël et février, relues à chaque déploiement) | – |
| 22 h 25 | Journal du job : `"reprise":{"valide":true,…},"annees_ouvertes":["2026-2027"]`. Si le job affiche **« Bascule arrêtée »** : retour arrière ci-dessous (N1). Si `annees_ouvertes` est vide (source officielle injoignable), relancer le job plus tard : la reprise se refait sans doublon | Pascal |
| 22 h 30 | Se connecter avec son ancien mot de passe GRR | admins |
| 22 h 32 | Admin → **E-mails** : vérifier que la redirection est active (encadré jaune) puis « M'envoyer un e-mail de test ». La liste des derniers envois montre ce qui est parti ou en échec, avec l'erreur | Pascal |
| 22 h 35 | Admin → Reprise → **Archives de la bascule** : télécharger la sauvegarde et l'export, les ranger hors d'OVH (Drive), vérifier qu'ils s'ouvrent avec le mot de passe des sauvegardes | Pascal |
| 22 h 40 | Écran Reprise : contrôle validé, 20 réservations à comparer avec GRR | admins |
| 22 h 50 | Écran Vacances : les attributions GRR de la saison dont la lignée est reconnue sont déjà des plages (`attributions_appliquees` dans le journal du job). Écran Reprise : valider les autres (choisir la lignée quand elle n'est pas devinée) | admins |
| 23 h 00 | Écran Vacances : vérifier Noël et février, compléter les plages | admins |
| 23 h 10 | Tester : réservation ouverte et exclusive, annulation, e-mails reçus, « Mot de passe oublié » | admins |

À vérifier à la main en production (critères non automatisables) :
- P2 : l'adresse du site affiche en HTTPS la page de connexion du nouveau site ;
- P3 : si MySQL 8.4 est retenu, la page de santé l'indique ;
- N3 : pendant la nuit, GRR ne répond plus (aucune réservation possible).

**Répétition** : ensuite, retour arrière (ci-dessous). Les tables du nouveau site restent ; la
reprise suivante les remet à jour sans doublon. Les archives restent dans `partage/storage/app/exports`.

**Bascule** : si tout est bon, on garde le dossier racine, puis :
- variables `REPRISE_GRR=non` ; `partage/.env` : `GRR_REPRISE=fermee` ;
- Admin → Reprise → « Jour J » (US-806-P2) : nouvelle adresse aux membres, lien de mot de passe
  aux comptes à réinitialiser (étape 5 de P1, déclenchée par un admin, jamais par la CI) ;
- GRR reste en place, inaccessible, jusqu'à la fin de la saison, pour consultation.

**Tables GRR** (N2) : elles ne se suppriment qu'avec `php artisan grr:supprimer-tables --confirmer`,
refusé tant que le dernier contrôle n'est pas validé et qu'aucun export chiffré intact n'est présent.
Pas avant la fin de la saison.

## E-mails : redirection pendant les tests

Écran **Admin → E-mails** : redirection en vigueur (réglée sur l'écran ou, à défaut, par
`MAIL_REDIRIGER_VERS` de `partage/.env`), changement de l'adresse, coupure (case à cocher
obligatoire), retour au réglage du serveur, e-mail de test envoyé tout de suite, et les
30 derniers envois avec leur état (parti, en attente, en échec et l'erreur). Chaque changement
est inscrit au journal. Les e-mails des réservations partent tout de suite ; la tâche planifiée
horaire ne fait que reprendre ceux qui ont échoué ou dépassé le plafond de 150 par heure.

Tant qu'une redirection est active, aucun membre ne reçoit rien.
Le garder de la première répétition jusqu'au message « Jour J » : l'e-mail « année ouverte »,
envoyé à l'ouverture de l'année pendant la première répétition, n'arrive ainsi qu'à Pascal
(l'année reste ouverte ensuite : il ne repart pas). Les membres ne reçoivent que l'annonce
de la bascule et le message « Jour J ».
Pour un envoi réel (annonce de la bascule, « Jour J », bascule) :
1. Admin → E-mails, « Derniers envois » : vérifier qu'aucun e-mail n'est « En attente » (un
   e-mail de test en attente partirait aux vrais destinataires dès la redirection levée) ;
2. « Couper la redirection » (case cochée) ;
3. seulement ensuite, déclencher l'envoi ;
4. pour l'annonce de la bascule : une fois « Parti », remettre la redirection (« Rediriger vers »)
   jusqu'au message « Jour J ».

Plusieurs années scolaires ouvertes d'un coup (première reprise) ne donnent qu'un seul e-mail
« année ouverte », qui les cite toutes.

Texte de l'annonce : relu sur l'écran Reprise (« Texte de l'annonce ») avant l'envoi, et
modifiable juste en dessous (« Modifier le texte de l'annonce » : un paragraphe par ligne vide,
`{date}` et `{duree}` remplacés à l'envoi, bouton « Revenir au texte d'origine »). Le texte
d'origine demande aux membres d'ajouter l'adresse d'envoi du site à leurs contacts et de
regarder dans leurs spams : le 15 novembre, les e-mails du site arrivaient en spam chez Gmail
malgré une note de 10/10 sur mail-tester (SPF, DKIM et DMARC en place). Elle part
quand l'admin clique, au moment qu'il choisit : aucun préavis minimal n'est imposé (décision de
Pascal du 7 nov. 2026). L'écran Reprise n'étant servi que pendant une répétition, l'envoyer
pendant celle du 23 novembre ou celle du 9 décembre. Ses chiffres (date
de la première réservation GRR, réservations, créneaux, nuits, membres) sont calculés depuis la
dernière reprise : lancer la reprise juste avant. Date de la mise à jour majeure de GRR, facultative :
`GRR_MISE_A_JOUR_MAJEURE=` dans `partage/.env` (et dans `.env` en local).

## Retour arrière (2 minutes)

En SFTP : `rm www` (supprime seulement le lien, pas le nouveau site) puis `rename www-grr www`.
GRR est servi de nouveau sur les cinq domaines, intact (le nouveau site ne l'a jamais modifié).
Variables du dépôt : `SITE_SERVI=non`, `REPRISE_GRR=non`.
Ne jamais toucher au `.ovhconfig` de la racine (GRR passerait en erreur 500).

Après un retour arrière automatique du job, la version précédente sert de version en cours :
le déploiement suivant renvoie alors tous les fichiers (environ 15 minutes au lieu de 2).

## Pièges vus en répétition (10, 11 et 15 novembre 2026)

- **Pare-feu OVH** : il renvoie 403 à un `POST` sans corps et à l'agent « curl » ; le script de
  déploiement envoie donc un corps et un agent à lui.
- **Fichier de maintenance** : `partage/storage/framework/down` doit contenir du JSON valide
  (un fichier vide donnait une erreur 500) ; l'adresse de migration du déploiement passe en
  dehors du groupe `web` (sessions et cache n'existent pas avant la première migration).
- **OPcache d'OVH** peut servir l'ancien code quelques minutes après un déploiement : une erreur
  qui disparaît seule n'est pas forcément à corriger.
- **Contrôle de fin** (`/sante`) : base et cache doivent répondre ; une tâche planifiée qui n'a
  encore jamais tourné n'est qu'un avertissement (la tâche OVH T10 reste à créer).
- **Approbation avant le lien SFTP** : le contrôle de fin tombe sur GRR (404). Poser le lien,
  puis « Re-run failed jobs ».
- **Journal du serveur** : `partage/storage/logs/laravel-AAAA-MM-JJ.log`, à lire en SFTP (`get`).
- **E-mails en spam** : prévenir les membres (annonce) et ajouter l'adresse d'envoi aux contacts.
- **E-mails perdus depuis l'hébergement** (15 nov. au soir) : en SMTP (`ssl0.ovh.net` ou
  `smtp.mail.ovh.net`), OVH accepte l'e-mail du site puis le perd sans erreur ; le même message
  envoyé depuis un ordinateur arrive dans la boîte de réception Gmail. `sendmail` est refusé
  (« Process failed »). Le site envoie donc par la fonction `mail()` de PHP, comme GRR :
  `MAIL_MAILER=php_mail` dans `partage/.env` (l'adresse d'expédition doit être d'un domaine
  rattaché à l'hébergement). Suivi : OVH → Hébergements → Plus → Scripts e-mail (compteurs,
  « E-mails en erreur »).

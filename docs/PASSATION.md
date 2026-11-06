# Passation — état du projet au 6 novembre 2026

Note de reprise pour une nouvelle session (Claude Code ou autre). Les règles permanentes sont
dans `CLAUDE.md` ; ce fichier dit où on en est. À mettre à jour à chaque étape importante.

## Fait et fusionné dans `main`

| PR | Contenu |
|---|---|
| #8 | Jalon 0 : socle, CI verte, actions épinglées, critères exportés, branche témoin |
| #10 | Connexion : Fortify, verrouillage, double authentification des admins, Google, profil |
| #11 | Créneaux, calendrier, réservation (verrou et test de concurrence), modification, annulation |
| #12 | CI : installation de Playwright robuste |
| #13 | Boîte d'envoi (150 par heure), gabarits d'e-mails, fichiers .ics |
| #14 | Vacances : source officielle (anti-SSRF), ouverture d'année, plages, conflits, rappels |
| #15 | Utilisateurs : invitations, désactivation, rôles, réinitialisation |
| #16 | Reprise GRR : comptes, réservations, attributions à valider, contrôle par année |
| #18 | Reprise : ajustements par login (`GRR_AJUSTEMENTS`), noms propres, abonnements e-mail |
| #19 | US-806 : annonce de la bascule (J-7) et messages du jour J |
| #20 | US-805 : sauvegarde et export GRR chiffrés avant migration, arrêt si le contrôle échoue, garde de suppression des tables GRR |
| #21 | Site local de test : `docker compose up`, données fictives |

La PR #17 (`lot/bascule`, d'une autre session) a été fermée sans fusion : son contenu utile
est dans #18, #19 et #20.

## En cours : branche `lot/nom-reservant-calendrier` (pas encore poussée)

Revue du site local avec Pascal les 5 et 6 novembre 2026. Les maquettes, le PRD, l'ADR 017 et les
user stories à jour sont dans `contexte/` (privé). Changements :

- **Vue mois** (maquette Main) : chaque créneau affiche le réservant (prénom et initiale), le
  nombre de personnes et le début du commentaire ; infobulle au survol ; le numéro du jour ouvre
  le détail. Flèches et « Aujourd'hui » à droite, à côté du choix Mois/Saison : elles ne bougent
  plus avec la longueur du nom du mois. L'encart « jour » à droite de la grille est supprimé.
- **Vue saison** (maquette Saison) : frise par mois, barres J/N par réservation.
- **Vue par défaut** : saison entre l'ouverture et la fermeture de la station (paramètres
  admin, sinon 1er décembre → 30 avril), mois le reste de l'année (US-202-P6, P7).
- **Sélection par clics** et pop-up de réservation (`resources/js/calendrier.js`, script maison
  plutôt qu'Alpine, formulaire classique plutôt que Livewire : écarts à l'ADR 017 signalés à
  Pascal). Après « Réserver », on reste sur la vue ; en cas de refus, la pop-up se rouvre.
- **Mes réservations dans la pop-up** (6 nov.) : un clic sur un créneau d'une de mes réservations
  ouvre la pop-up en édition (type, personnes, commentaire, invités ; lien vers la page de
  modification pour changer les dates). Bouton « Annuler la réservation », comme dans « Mes
  réservations », dans la pop-up et sur l'écran de modification. Les créneaux des autres restent refusés.
- **Un créneau, une réservation** (décision du 5 nov.) : un créneau déjà réservé, même ouvert, ne
  se réserve plus, côté navigateur et serveur. « Me joindre » est retiré. Critères retirés :
  US-204-P2, US-301-P2, US-304, US-305-N4, US-502 ; ajouté : US-301-N9. US-308-P1 est à réécrire
  dans les specs (le test vérifie maintenant qu'une seule réservation est créée).
- **Commentaire** visible de tous, 200 caractères au plus ; invités masqués aux autres membres.
- **Charte n° 5 « Après-ski »** : palette, Fraunces (servie par le site), arrondis, ombres ;
  branches en avocat, sarcelle et prune avec ● ■ ▲ ; trames des créneaux retirées ; texte des
  cases du mois en 12,5 px (écart à la charte, 16 px minimum, voulu par Pascal). Favicon cabine
  rouge et bandeau animé (Réserver, Modifier, Mes réservations). Couleurs de lignée servies par
  `/lignees.css` (la CSP interdit les styles en ligne). **En production, mettre les couleurs de
  la charte dans `LIGNEES` de `partage/.env`.**

Pour pousser : `gh auth login` sur le Mac de Pascal, puis PR vers `main`.

## Critères d'acceptation

219 critères MVP sur 296 ont un test (`composer criteres`). Il manque surtout :
- **épique 9** (technique et sécurité) : US-901 à US-911, environ 54 critères. Beaucoup sont
  assurés par la CI (Larastan, Semgrep, Gitleaks, ZAP) sans test portant leur identifiant ;
- **US-906** (6) : parcours dans un vrai navigateur, ordinateur et iPhone — **prioritaire** ;
- US-804 (5) et US-805 (3 : P2, P3, N3) : vérifiables seulement en production, pendant les
  répétitions (liste dans `deploiement/BASCULE.md`) ;
- quelques-uns en calendrier et réservation (US-201, 202, 203, 303, 304, 306), US-101, 403, 601.

## Prochaines étapes, dans l'ordre

1. **T09 — premier déploiement en mode préparation**, avec Pascal (il a les accès) :
   accès SFTP OVH, clé de déploiement dédiée, secrets GitHub `SFTP_HOTE`, `SFTP_UTILISATEUR`,
   `SFTP_CLE`, `SFTP_EMPREINTE_HOTE` ; variables `SITE_URL`, `DOSSIER_DISTANT`,
   `DEPLOIEMENT_ACTIF=oui`, `SITE_SERVI=non`, `REPRISE_GRR=non` ; `partage/.env` sur le serveur
   (dont `GRR_PREFIXE`, `GRR_AJUSTEMENTS`, `LIGNEES`, `BACKUP_ARCHIVE_PASSWORD` d'au moins
   12 caractères). GRR reste servi. **Rien n'a encore tourné sur OVH.**
2. **T10 / T03** : tâche planifiée OVH horaire (`artisan schedule:run` en PHP 8.5), et constater
   deux passages réels.
3. **US-906** : parcours navigateur (connexion, réservation ouverte puis exclusive, conflit,
   modification et annulation, invitation, attribution d'une plage).
4. **Restes du lot MVP 2** : purge des adresses IP du journal (`journal:purger-ip`, T30), copie
   des sauvegardes hors OVH (T32), vérifier l'écran des fermetures de l'appartement (T31).
5. **Première répétition de nuit vers le 23 novembre**, puis seconde vers le 9 décembre avec
   recette par les trois admins (`deploiement/BASCULE.md`).
6. **Bascule le 12-13 décembre.** Suppression des tables GRR pas avant la fin de la saison.

## Questions ouvertes (à trancher avec Pascal, données dans GRR)

- Deux réservations GRR finissent à 12 h un lundi : lues aujourd'hui comme s'arrêtant avant la
  « Journée » du départ. À confirmer.
- Une entrée GRR décrit un échange entre deux lignées : vérifier à quelle lignée la reprise
  doit l'attribuer.
- Le domaine principal pointe encore sur la page « Site en construction » d'OVH : son champ A
  est à corriger avant la bascule (US-805-P2).

## Dates

| Date | Étape |
|---|---|
| vers le 23 novembre | 1re répétition de nuit (PHP 8.5, migrations, délai, retour à GRR) |
| vers le 9 décembre | 2e répétition : import à blanc de la vraie base, recette des admins |
| J-7 (5 décembre au plus tard) | annonce aux membres (écran Reprise, US-806) |
| 12-13 décembre | bascule |
| 19 décembre | MVP en service avant Noël |

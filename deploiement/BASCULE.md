# Répétition et bascule de nuit

Procédure des nuits de répétition (vers le 23 novembre et vers le 9 décembre 2026)
et de la bascule (12-13 décembre 2026). ADR 004 révisé, ADR 014, US-804 à US-806.

L'offre OVH Starter n'a qu'un site : basculer, c'est changer son **dossier racine**
de `www` (GRR) à `narbonnaise-v2/actuelle/public` (nouveau site). Revenir, c'est
remettre `www`. Les données de GRR (tables `NAR_*`) ne sont jamais modifiées par
la reprise : elles sont seulement lues.

> **Ne jamais modifier le `.ovhconfig` de la racine.** Le passer en PHP 8.5 met
> GRR en erreur 500 (26 octobre 2026) et empêcherait le retour arrière. Le nouveau
> site a son propre `.ovhconfig`, dans `narbonnaise-v2/actuelle/public`.

## Une fois pour toutes (tâche T09)

Dans `narbonnaise-v2/partage/.env` sur le serveur (jamais dans le dépôt) :

```
GRR_PREFIXE=NAR_
GRR_ADMINS=<login GRR des admins à ajouter>
GRR_DESACTIVES=<login du compte partagé>
LIGNEES="<Nom>:<#couleur>,…"
```

Dans GitHub, environnement `production` : secrets SFTP, variables `SITE_URL`,
`DOSSIER_DISTANT=narbonnaise-v2`, `DEPLOIEMENT_ACTIF=oui`, `SITE_SERVI=non`, `REPRISE=non`.

## J-7 au plus tard (bascule seulement) : l'annonce

Depuis l'écran « Exécuter une commande » n'existant pas sur l'offre Starter, l'annonce
part de la tâche planifiée ou d'un poste avec accès à la base : `php artisan
bascule:annoncer 2026-12-12 --duree="une nuit, de 22 h à 6 h"`. Elle refuse de
partir moins de 7 jours avant. Destinataires en copie cachée, comptes désactivés exclus.

## La nuit (répétition comme bascule)

| Heure | Étape | Qui | Durée mesurée |
|---|---|---|---|
| 22 h 00 | **GRR en lecture seule** : phpMyAdmin, `UPDATE NAR_room SET statut_room = '0';` (salle indisponible à la réservation). Vérifier qu'une réservation est refusée dans GRR. | admin | |
| 22 h 05 | **Sauvegarde complète** de la base : OVH → Bases de données → Sauvegardes → « Créer une sauvegarde », puis export SQL phpMyAdmin chiffré (`gpg -c`) et copié hors OVH. | admin | |
| 22 h 20 | **Dossier racine** : OVH → Hébergement → Multisite → le domaine → Modifier → dossier racine `narbonnaise-v2/actuelle/public`. Attendre la propagation (quelques minutes). | admin | |
| 22 h 30 | **Déploiement avec reprise** : passer les variables `SITE_SERVI=oui` et `REPRISE=oui`, puis Actions → `ci` → « Run workflow » sur `main`, et approuver l'environnement `production`. Le script fait migrations, import de GRR, rapport de contrôle, test de fumée, et affiche la durée de chaque étape. | admin | |
| 22 h 45 | **Rapport** : la réponse de la reprise s'affiche dans le journal du job (totaux seulement). `valide: true` attendu. Sinon (409), le script a remis l'ancienne version : **retour arrière** ci-dessous. | admin | |
| 22 h 50 | **Contrôle à la main** : écran Administration → Reprise de GRR (comparaison par année, 20 réservations tirées au hasard, attributions à valider). Connexion, réservation ouverte, réservation exclusive, annulation (US-804-P2). | admin | |
| 23 h 00 | Répétition : **retour arrière** systématique. Bascule : **mise en service** (ci-dessous). | admin | |

### Retour arrière (répétition, ou rapport non validé)

1. OVH → Multisite → dossier racine **`www`**. Mesurer le temps jusqu'à ce que GRR réponde (US-804-N3).
2. phpMyAdmin : `UPDATE NAR_room SET statut_room = '1';` (GRR de nouveau réservable).
3. GitHub : remettre `SITE_SERVI=non` et `REPRISE=non`.
4. Reporter les durées mesurées dans ce tableau et dans le plan de bascule.

### Mise en service (bascule seulement)

1. Garder `SITE_SERVI=oui`, remettre `REPRISE=non` (les déploiements suivants ne réimportent plus).
2. Valider les attributions de vacances proposées (Administration → Reprise de GRR).
3. Envoyer les messages du jour J : `php artisan bascule:annoncer --mise-en-service`
   (lien de mot de passe valable 7 jours aux comptes « à réinitialiser », nouvelle adresse aux autres).
4. Garder les tables `NAR_*` et la sauvegarde chiffrée au moins jusqu'à la fin de la saison.

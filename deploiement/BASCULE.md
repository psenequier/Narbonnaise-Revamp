# Répétitions et bascule de GRR vers le nouveau site

Procédure des nuits de répétition (vers le 23 novembre et le 9 décembre 2026) et de la
bascule (12-13 décembre). L'offre OVH Starter n'a qu'un site : on change son dossier racine,
et le retour arrière consiste à le remettre sur `www`. GRR et ses tables `NAR_*` ne sont
jamais modifiés par le nouveau site.

> Dépôt public : aucune valeur réelle ici. Les logins, noms de lignées et secrets vivent
> dans `partage/.env` sur le serveur et dans les secrets GitHub.

## Une fois, avant la première répétition (tâche T09)

1. SFTP activé, clé de déploiement, secrets GitHub (`SFTP_*`, `SITE_URL`, `DOSSIER_DISTANT`).
2. `partage/.env` sur le serveur, en plus des réglages de base :
   - `GRR_PREFIXE=NAR_`
   - `GRR_AJUSTEMENTS=<login perso de l'admin>:admin,<login du compte partagé>:desactive`
   - `GRR_REPRISE=ouverte`
   - `LIGNEES="<Nom>:#couleur,<Nom>:#couleur,<Nom>:#couleur"` (les trois lignées réelles)
3. Variables du dépôt (Settings → Secrets and variables → Actions → Variables) :
   `DEPLOIEMENT_ACTIF=oui`, `SITE_SERVI=non`, `REPRISE_GRR=non`.

## Nuit de répétition (ou de bascule)

| Heure | Action | Qui |
|---|---|---|
| 22 h 00 | Prévenir les membres : site indisponible jusqu'à minuit | admin |
| 22 h 05 | OVH → Hébergement → Multisite : dossier racine `narbonnaise-v2/actuelle/public` | Pascal |
| 22 h 10 | Variables du dépôt : `SITE_SERVI=oui`, `REPRISE_GRR=oui` | Pascal |
| 22 h 12 | Actions → ci → Run workflow sur `main`, approuver l'environnement « production » | Pascal |
| 22 h 25 | Le journal du job affiche `"reprise":{"valide":true,…}` | – |
| 22 h 30 | Se connecter avec son ancien mot de passe GRR, activer la double authentification | admins |
| 22 h 40 | Écran Admin → Reprise : contrôle validé, 20 réservations à comparer avec GRR | admins |
| 22 h 50 | Écran Reprise : valider les attributions (choisir la lignée quand elle n'est pas devinée) | admins |
| 23 h 00 | Écran Vacances : vérifier Noël et février, compléter les plages | admins |
| 23 h 10 | Tester : réservation ouverte et exclusive, annulation, e-mails reçus, « Mot de passe oublié » | admins |

**Répétition** : ensuite, retour arrière (ci-dessous). Les tables du nouveau site restent ; la
reprise suivante les remet à jour sans doublon.

**Bascule** : si tout est bon, on garde le dossier racine, puis :
- variables `REPRISE_GRR=non` ; `partage/.env` : `GRR_REPRISE=fermee` ;
- e-mail d'annonce aux membres (US-806) : nouvelle adresse, **connexion par l'adresse e-mail**,
  mot de passe inchangé sauf pour les comptes à réinitialiser (« Mot de passe oublié ») ;
- GRR reste en place, inaccessible, jusqu'à la fin de la saison, pour consultation.

## Retour arrière (2 minutes)

OVH → Multisite : dossier racine `www`. GRR est servi de nouveau, intact.
Variables du dépôt : `SITE_SERVI=non`, `REPRISE_GRR=non`.
Ne jamais toucher au `.ovhconfig` de la racine (GRR passerait en erreur 500).

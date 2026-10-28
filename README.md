# Appartement Chamrousse

Site privé de réservation de l'appartement familial à Chamrousse.
Il remplace l'ancien GRR hébergé chez OVH.

Documents de référence (privés, sur claude.ai) : PRD, ADR 001 à 016, user stories et critères d'acceptation, RFC.

## Pile technique

| Élément | Version | Pourquoi |
| --- | --- | --- |
| PHP | 8.5 | disponible sur l'hébergement OVH (environnement stable64) depuis novembre 2025 |
| Laravel | 13 | ADR 002 |
| Livewire, Blade, Tailwind 4 | 4.x | interface rendue côté serveur (ADR 003) |
| MySQL | 8.0 en production, 8.4 testé en CI | base unique de l'offre Starter (ADR 004) |
| Fortify, Socialite | 1.x, 5.x | connexion, double authentification des admins, Google sans création de compte (ADR 010) |
| Pest 5, Pest Browser, Larastan, Psalm, Semgrep, ZAP | — | tests et sécurité (ADR 013, ADR 016) |

## Installer en local

Prérequis : PHP 8.5 avec `pdo_mysql`, `sockets` et `intl`, Composer 2, Node 22 et MySQL 8.4 (ou Docker).

```bash
git clone <dépôt> chamrousse && cd chamrousse
composer install          # crée composer.lock au premier lancement (à commiter)
npm install               # crée package-lock.json (à commiter)
cp .env.example .env && php artisan key:generate

# Base locale
docker run -d --name mysql-chamrousse -e MYSQL_ROOT_PASSWORD= -e MYSQL_ALLOW_EMPTY_PASSWORD=yes \
  -e MYSQL_DATABASE=chamrousse -p 3306:3306 mysql:8.4

php artisan migrate --seed --seeder=DonneesFictivesSeeder
npm run dev & php artisan serve
```

Comptes fictifs : `admin@example.test` et `membre@example.test`, mot de passe `Mot-de-passe-de-test-42!`.
Les e-mails sont écrits dans `storage/logs` et ne partent jamais.

## Tester sur son ordinateur (Mac ou Windows, sans rien installer d'autre que Docker)

Pour cliquer dans le nouveau site sans toucher à GRR : données fictives uniquement, aucun e-mail ne part.

1. Installer [Docker Desktop](https://www.docker.com/products/docker-desktop/) (gratuit pour un usage personnel) et le lancer.
2. Récupérer le code : sur GitHub, **Code → Download ZIP**, puis décompresser (ou `git clone`).
3. Dans le Terminal, depuis le dossier décompressé : `docker compose up`
   La première fois, compter 5 à 10 minutes (téléchargements). Le site est prêt quand s'affiche
   « ✔ Site prêt ».
4. Ouvrir http://localhost:8000 et se connecter avec `admin@example.test` ou `membre@example.test`,
   mot de passe `Mot-de-passe-de-test-42!`. L'admin doit d'abord activer la double authentification
   (application d'authentification sur le téléphone).

Arrêter : `Ctrl+C` dans le Terminal. Relancer : `docker compose up` (les données sont gardées).
Repartir de zéro : `REINITIALISER=oui docker compose up`. Les e-mails sont écrits dans `storage/logs`.

## Tests

```bash
composer test                       # unitaires et fonctionnels (une base MySQL « test » doit exister)
composer test:browser               # parcours navigateur ; avant : npx playwright install chromium webkit
composer analyse                    # Larastan puis Psalm en analyse de propagation
composer criteres                   # chaque critère d'acceptation a-t-il son test ?
```

Règles :

- **Un test par critère d'acceptation.** Son nom commence par l'identifiant : `it('US-301-N1 refuse …')`. La liste attendue est dans `tests/criteres.txt`. Elle s'exporte depuis le document des user stories.
- **Aucune donnée réelle** dans les tests. Pas d'adresse e-mail réelle, pas d'extrait non anonymisé de GRR.
- **L'heure est figée** (`$this->travelTo(...)`) dans tout test qui dépend de la date.
- **Aucun appel réseau réel.** `Http::preventStrayRequests()` est actif, et les sources sont simulées avec `Http::fake`.

## Organisation du code

```
app/Domain/          règles métier, sans dépendance au framework si possible
  Calendrier/        créneaux jour et nuit, calcul des périodes de vacances
  Equite/            règle des réservations multiples sur 12 mois glissants
  Reservations/      ServiceReservation : la transaction en 5 étapes
  Sources/           filtre anti-SSRF des URL configurables
app/Models/          modèles Eloquent ; Journal est en ajout seul
app/Http/            contrôleurs, en-têtes de sécurité, /sante, route de migration
database/migrations/ schéma du RFC, dont la vue v_occupation des statistiques
deploiement/         script de déploiement SFTP
.github/workflows/   ci.yml (chaque PR et main) et hebdo.yml (lundi 3 h)
```

Conventions :

- Code et commentaires en français pour le métier (`creneau`, `lignee`). Les noms imposés par Laravel restent en anglais (`User`, `password`).
- `declare(strict_types=1)` dans `app/`, et Larastan au niveau 8.
- Une règle métier ne s'écrit qu'une fois, dans `app/Domain`. Les composants Livewire l'appellent, sans la recopier.
- Toute requête SQL passe des paramètres liés. `{!! !!}` est interdit dans les vues (règle Semgrep du projet).
- Toute URL saisie par un admin passe par `UrlSourceSure::valider()` avant d'être appelée.

## Dépôt public : règles

Le code est public ; les données, jamais. Le site reste privé et protégé par la connexion : la sécurité ne repose pas sur le secret du code (OWASP 2025 A06).

- **Aucune donnée réelle** dans le dépôt, ni dans les tickets, ni dans les journaux de CI. Pas de noms de membres, pas de noms de lignées (variable `LIGNEES` du `.env` de production), pas d'adresse du site, pas d'extrait de GRR, même anonymisé : les tests de reprise utilisent un jeu GRR synthétique.
- **Aucun secret** : Gitleaks en CI, et la protection des secrets à l'envoi de GitHub (gratuite pour un dépôt public) refuse un `git push` qui en contient.
- **Failles signalées en privé** : signalement privé des vulnérabilités activé (voir `SECURITY.md`). Les workflows ne publient jamais d'alerte en ticket.
- **Contributions extérieures** : une pull request venue d'un fork ne reçoit aucun secret, et ses workflows attendent l'approbation d'un admin (réglage « Require approval for all outside collaborators »). Le déploiement n'existe que sur `main`, après approbation.

Réglages GitHub à activer à la création (T04) : règles de protection de `main` (PR obligatoire, CI verte, pas de push forcé), environnement `production` avec les admins comme approbateurs, Dependabot (alertes et mises à jour), analyse de code (résultats SARIF de Psalm et Semgrep), protection des secrets à l'envoi, signalement privé des vulnérabilités.

## Branches et revues

- `main` est protégée : on y entre par une pull request, avec la CI au vert et la relecture d'un autre admin quand c'est possible.
- Branches de travail : `us-301-reserver`, `correctif-…`.
- La branche `temoin` contient des failles volontaires et ne doit jamais être fusionnée. Le workflow hebdomadaire vérifie que la CI la bloque bien.

## Intégration continue

| Job | Quand | Bloque si |
| --- | --- | --- |
| `tests` (MySQL 8.0 et 8.4) | chaque PR et push | test en échec, couverture inférieure à 90 %, erreur Larastan, critère sans test |
| `sast` | chaque PR | alerte Psalm (taint) ou Semgrep de gravité ERROR |
| `dependances` | chaque PR | vulnérabilité élevée Composer ou npm, secret détecté (Gitleaks), `.env` commis |
| `interface` | après les trois précédents | parcours navigateur en échec, alerte ZAP passive moyenne ou plus |
| `deploiement` | push sur `main`, après approbation d'un admin | test de fumée en échec, avec retour arrière automatique |
| `hebdo` | lundi 3 h | alerte du scan ZAP actif, ou branche témoin non bloquée |

Avant la première fusion, épingler les actions par empreinte SHA (`pinact run`). Dependabot les tient ensuite à jour.

Quota : le dépôt est public, les minutes GitHub Actions sur les runners standard sont donc gratuites et illimitées.

### Secrets et variables GitHub (environnement `production`)

| Nom | Type | Contenu |
| --- | --- | --- |
| `SFTP_HOTE`, `SFTP_UTILISATEUR` | secret | accès SFTP de l'hébergement |
| `SFTP_CLE` | secret | clé privée SSH dédiée au déploiement (clé publique déclarée chez OVH) |
| `SFTP_EMPREINTE_HOTE` | secret | ligne `known_hosts` du serveur SFTP, relevée une fois à la main |
| `SITE_URL` | variable | adresse du site en production (non écrite dans le dépôt) |
| `DOSSIER_DISTANT` | variable | `narbonnaise-v2` |
| `DEPLOIEMENT_ACTIF` | variable | `oui` pour activer le job de déploiement (à partir de T09) |
| `SITE_SERVI` | variable | `non` jusqu'à la bascule (dépôt sans mise en service), puis `oui` |

Aucun identifiant de la base de production n'est confié à GitHub. Ils restent dans `partage/.env` sur le serveur.

## Hébergement OVH Starter

```
racine FTP/
  .ovhconfig                 GRR, PHP 7.4, container.image=stable64 (obligatoire)
  www/                       GRR : dossier racine du site jusqu'à la bascule
  narbonnaise-v2/
    actuelle/                version déposée (servie après la bascule)
      public/.ovhconfig      PHP 8.5, stable64 (livré avec chaque version)
    precedente/              version de retour arrière
    releases/<sha>/
    partage/.env
    partage/storage/
```

À savoir :

- **Un seul site sur Starter.** L'offre n'autorise qu'un site, donc un seul dossier racine pour tous les domaines (aujourd'hui `www`, GRR). OVH n'applique un `.ovhconfig` qu'à la racine du compte ou dans le dossier racine du site ; un sous-dossier reste dans la version de la racine (vérifié le 27/10/2026). GRR en 7.4 et la nouvelle application en 8.5 ne peuvent donc pas être servies en même temps : **bascule en une fois** (ADR 004 révisé).
- **Ne jamais modifier le `.ovhconfig` de la racine** (GRR, PHP 7.4, `stable64`). Le passer en 8.5 met GRR en erreur 500 (vu le 26/10/2026).
- **Basculer** = dans « Mes sites », modifier le dossier racine du site de `www` vers `narbonnaise-v2/actuelle/public`. **Revenir à GRR** = remettre `www`. C'est aussi ce que font les deux répétitions de nuit avant la bascule.
- **Tâche planifiée OVH**, à créer dans l'espace client :
  - script : `narbonnaise-v2/actuelle/artisan`, argument `schedule:run` ;
  - langage PHP 8.5, toutes les heures (c'est le minimum) ;
  - OVH coupe une exécution au bout de 60 minutes et désactive la tâche après 10 échecs consécutifs ;
  - le rapport d'erreur n'arrive qu'une fois par jour, la nuit. `/sante` signale un retard de plus de 2 heures.
- **Envoi des e-mails.** Ils partent par le SMTP MX Plan (`ssl0.ovh.net:465`). OVH ne publie pas de plafond officiel ; la communauté cite environ 200 envois par heure et par compte. L'application s'en tient à 150 par heure (file `emails_sortants`).

## Déployer

Le chemin normal : fusionner dans `main`, puis un admin approuve le déploiement dans l'onglet Actions.

Avant la bascule (`SITE_SERVI=non`), le script envoie la version et la range dans `actuelle/`, sans rien mettre en service : le site sert encore GRR. Les migrations passent pendant les répétitions.

Après la bascule (`SITE_SERVI=oui`), `deploiement/deployer.sh` enchaîne ces étapes :

1. Il envoie la version dans `releases/<sha>/`.
2. Il met le site en maintenance et bascule : `actuelle` devient `precedente`, puis la nouvelle version devient `actuelle`.
3. Il lance les migrations par `POST /_deploiement/migrer`, avec un jeton à usage unique dont seule l'empreinte est déposée sur le serveur.
4. Il lève la maintenance et fait le test de fumée : `/sante`, puis les en-têtes CSP et HSTS de `/login`.
5. Si le test échoue, il fait le renommage inverse. Les migrations ne sont jamais annulées automatiquement. Elles doivent donc rester compatibles avec la version précédente.

Premier déploiement, à faire à la main une seule fois (les noms réels des lignées vont dans `LIGNEES` du `.env` de production) :

1. Créer `narbonnaise-v2/partage/.env`, à partir de `.env.example`, avec `APP_ENV=production` et `APP_DEBUG=false`.
2. Créer `partage/storage/` avec ses sous-dossiers.
3. Créer `actuelle/`, vide.
4. Créer la tâche planifiée en PHP 8.5 (voir plus haut). Le dossier racine du site ne change qu'à la première répétition.

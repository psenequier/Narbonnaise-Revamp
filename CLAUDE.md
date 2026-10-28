# CLAUDE.md — site de réservation de l'appartement de Chamrousse

Lu au début de chaque session. Le README (installation, conventions, organisation du code)
et `deploiement/BASCULE.md` (nuits de répétition et de bascule) complètent ce fichier.
L'état d'avancement et les prochaines étapes sont dans `docs/PASSATION.md`.

## Le projet

Site privé de réservation d'un appartement familial (une SCI, trois lignées), qui remplace
GRR 4.1.1. Laravel 13, PHP 8.5, Fortify, Pest 5, MySQL. Hébergement OVH Starter : **un seul
site**, pas de SSH, déploiement par SFTP. GRR et le nouveau site ne peuvent pas être servis en
même temps : **bascule en une fois** le week-end du 12-13 décembre 2026, après deux
répétitions de nuit (vers le 23 novembre et le 9 décembre). Le MVP doit tourner avant Noël.

Le propriétaire du projet (Pascal) écrit en français : répondre en français, simplement.

## Règles à ne jamais enfreindre

1. **Ne jamais modifier le `.ovhconfig` de la racine de l'hébergement.** Le passer en PHP 8.5
   met GRR en erreur 500 (vu le 26 octobre 2026) et empêche le retour arrière. Le seul
   `.ovhconfig` du dépôt est `public/.ovhconfig`, pour le dossier du nouveau site.
2. **Ne jamais fusionner ni supprimer la branche `temoin`.** Elle contient des failles
   volontaires ; la CI doit échouer dessus chaque semaine.
3. **Dépôt public : aucune donnée réelle.** Pas de vrai nom de lignée, de membre, d'adresse
   e-mail, de login GRR, de domaine ou d'extrait de la base GRR. Adresses de test en
   `@example.test` uniquement. Les valeurs réelles vivent dans `partage/.env` sur le serveur et
   dans les secrets GitHub. Une copie de la base GRR ne se charge jamais dans le dépôt.
4. **GRR n'est jamais modifié** par le nouveau site : lecture seule de ses tables (préfixe
   configurable, `GRR_PREFIXE`), suppression uniquement par `grr:supprimer-tables`, gardée.
5. **Aucune fonctionnalité payante** (services, offres, extensions).
6. **C'est Pascal qui fusionne.** Ne jamais fusionner une PR, ni pousser sur `main`.

## Façon de travailler

- Une branche par sujet : `lot/…`, `correctif/…` (les branches `diagnostic/…` relancent tous
  les contrôles, voir `.github/workflows/diagnostic.yml`). Puis une PR vers `main`.
- Une PR n'est proposée à la fusion que **CI entièrement verte** : tests (MySQL 8.0 et 8.4),
  Larastan, Psalm, Semgrep, dépendances, interface (Playwright + ZAP). Pascal fusionne en
  « Squash and merge » puis supprime la branche.
- Messages de commit et de PR en français.
- Un test par critère d'acceptation, nommé par son identifiant : `it('US-301-N1 …')`.
  Liste : `tests/criteres.txt` (exportée du document des user stories). `composer criteres`
  liste les critères sans test.
- Avant de pousser : `composer test`, `composer analyse` (Larastan niveau 8, puis Psalm en
  analyse de propagation).

## Commandes utiles

```bash
docker compose up                      # site local, données fictives, http://localhost:8000
REINITIALISER=oui docker compose up    # base remise à zéro
composer test                          # tests (une base MySQL « test » doit exister)
composer test:browser                  # parcours navigateur
composer analyse                       # Larastan + Psalm
composer criteres                      # critères d'acceptation sans test
```

Comptes fictifs : `admin@example.test`, `membre@example.test`, mot de passe
`Mot-de-passe-de-test-42!` (l'admin doit activer la double authentification).

## Pièges déjà rencontrés

- **Semgrep refuse le SQL concaténé** (`DB::select("…" . $x)`). Paramètres liés partout ; un
  nom de table, qui ne peut pas être lié, passe par `ExportBascule::nomSql()` (validation
  stricte) et la requête est construite dans une variable, avec un commentaire.
- **`SHOW TABLES LIKE ?` ne se prépare pas** sous MySQL : passer par `information_schema.tables`.
- **Tests et tables GRR** : la fixture `tests/Feature/Reprise/grr-4.1.1.sql` crée des tables
  **temporaires**, absentes de `SHOW TABLES` et d'`information_schema`. Un `DROP TABLE` sans
  `TEMPORARY` provoque un commit implicite qui casse `RefreshDatabase`.
- **`php artisan serve` ne transmet pas l'environnement du conteneur** à ses processus : les
  réglages doivent être dans `.env` (voir `local/demarrer.sh`).
- **Larastan** : `Carbon::locale('fr')` renvoie `Carbon|string` ; appeler `locale()` seul, puis
  `translatedFormat()` sur la variable.
- **`Http::fake` empilés** : utiliser `reinitialiserHttp()` (tests/Pest.php).
- **Comparer des jours civils** en UTC après conversion depuis Paris (bug corrigé dans la règle
  d'équité entre minuit et 1 h).
- **Envois d'e-mails** : toujours par la boîte d'envoi (`BoiteEnvoi`, table `emails_sortants`),
  150 destinataires par heure au plus (limite OVH), copie cachée pour les envois groupés.
- **Concurrence des réservations** : `lockForUpdate` dans `ServiceReservation` ; tests en vrais
  processus dans la suite « Concurrence ».
- **Les journaux de la CI** ne sont lisibles qu'avec un compte GitHub : pour un diagnostic
  automatique, faire remonter les erreurs en annotations (`::error::`), comme `local.yml`.

## Où trouver le reste

- **Dossier `contexte/`** (privé, ignoré par git, présent seulement sur le poste de Pascal) :
  PRD, RFC, ADR, user stories (texte complet des critères), plan, maquettes. Le lire pour toute
  question de besoin ou de critère. Il contient des données réelles : **ne jamais en copier un
  nom de lignée, un domaine ou un nom de personne dans le dépôt**, ni le commettre. S'il est
  absent, demander à Pascal le passage utile. Le dépôt n'en garde que les identifiants de
  critères (`tests/criteres.txt`).
- `deploiement/deployer.sh` et `deploiement/BASCULE.md` : déploiement SFTP et nuits de bascule.
- `docs/PASSATION.md` : où en est le projet, ce qui reste, les questions ouvertes.

#!/usr/bin/env bash
# Démarrage du site local (conteneur « site » de compose.yaml). Données fictives, ou copie de la
# vraie base chargée par local/charger-grr.sh (gardée tant qu'on ne demande pas REINITIALISER=oui).
set -euo pipefail
cd /app

echo "▶ Dépendances PHP et JavaScript (long la première fois)"
composer install --no-interaction --no-progress
npm ci --ignore-scripts --no-audit --no-fund
npm run build

if [[ ! -f .env ]]; then
  cp .env.example .env
fi
# « php artisan serve » ne transmet pas les variables du conteneur à ses processus : on les écrit dans .env.
sed -i "s|^DB_HOST=.*|DB_HOST=${DB_HOST:-mysql}|; s|^APP_URL=.*|APP_URL=${APP_URL:-http://localhost:8000}|" .env
# Les e-mails vont dans Mailpit (http://localhost:8025) : jamais vers Internet, même avec la vraie base.
sed -i "s|^MAIL_MAILER=.*|MAIL_MAILER=smtp|; s|^MAIL_HOST=.*|MAIL_HOST=mailpit|; s|^MAIL_PORT=.*|MAIL_PORT=1025|; s|^MAIL_SCHEME=.*|MAIL_SCHEME=smtp|; s|^MAIL_USERNAME=.*|MAIL_USERNAME=|; s|^MAIL_PASSWORD=.*|MAIL_PASSWORD=|" .env
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

if [[ "${REINITIALISER:-non}" = "oui" ]] || { [[ ! -f storage/app/.donnees-fictives ]] && [[ ! -f storage/app/.donnees-grr ]]; }; then
  echo "▶ Base remise à zéro avec les données fictives"
  php artisan migrate:fresh --seed --seeder=DonneesFictivesSeeder --force
  rm -f storage/app/.donnees-grr
  touch storage/app/.donnees-fictives
else
  php artisan migrate --force
fi

# Comme à chaque déploiement : calendrier scolaire officiel relu, année en cours ouverte si besoin.
php artisan vacances:verifier || echo "⚠ Calendrier scolaire officiel injoignable : l'année sera ouverte au prochain démarrage."

# Envoi de la boîte d'envoi toutes les minutes, vers Mailpit.
( while true; do php artisan emails:envoyer --max=150 > /dev/null 2>&1 || true; sleep 60; done ) &

if [[ -f storage/app/.donnees-grr ]]; then
  echo "✔ Site prêt avec la copie de la base GRR : http://localhost:8000  (e-mails : http://localhost:8025)"
else
  echo "✔ Site prêt : http://localhost:8000  (admin@example.test ou membre@example.test, mot de passe Mot-de-passe-de-test-42!)"
  echo "  E-mails envoyés par le site : http://localhost:8025"
fi
exec php artisan serve --host=0.0.0.0 --port=8000

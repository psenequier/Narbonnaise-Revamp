#!/usr/bin/env bash
# Démarrage du site local (conteneur « site » de compose.yaml). Données fictives seulement.
set -euo pipefail
cd /app

echo "▶ Dépendances PHP et JavaScript (long la première fois)"
composer install --no-interaction --no-progress
npm ci --no-audit --no-fund
npm run build

if [ ! -f .env ]; then
  cp .env.example .env
fi
# « php artisan serve » ne transmet pas les variables du conteneur à ses processus : on les écrit dans .env.
sed -i "s|^DB_HOST=.*|DB_HOST=${DB_HOST:-mysql}|; s|^APP_URL=.*|APP_URL=${APP_URL:-http://localhost:8000}|" .env
grep -q '^APP_KEY=base64:' .env || php artisan key:generate --force

if [ "${REINITIALISER:-non}" = "oui" ] || [ ! -f storage/app/.donnees-fictives ]; then
  echo "▶ Base remise à zéro avec les données fictives"
  php artisan migrate:fresh --seed --seeder=DonneesFictivesSeeder --force
  touch storage/app/.donnees-fictives
else
  php artisan migrate --force
fi

# Les e-mails ne partent jamais : ils sont écrits dans storage/logs (MAIL_MAILER=log).
( while true; do php artisan emails:envoyer --max=150 > /dev/null 2>&1 || true; sleep 60; done ) &

echo "✔ Site prêt : http://localhost:8000  (admin@example.test ou membre@example.test, mot de passe Mot-de-passe-de-test-42!)"
exec php artisan serve --host=0.0.0.0 --port=8000

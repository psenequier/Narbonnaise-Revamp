#!/usr/bin/env bash
# Charge une copie de la vraie base GRR dans le site local, puis lance la reprise.
#
#   docker compose up                                   (dans un premier Terminal)
#   bash local/charger-grr.sh contexte/sauvegarde.sql.gz  (dans un second, depuis le dossier du site)
#
# La sauvegarde vient d'OVH (Hébergement → Bases de données → Télécharger la sauvegarde).
# Elle contient des données réelles : elle doit rester dans contexte/ (ignoré par git), et
# elle n'est jamais envoyée ailleurs. GRR et la production ne sont pas touchés.
# Les e-mails du site local partent tous dans Mailpit (http://localhost:8025), jamais vers Internet.
# Revenir aux données fictives : REINITIALISER=oui docker compose up
set -euo pipefail
cd "$(dirname "$0")/.."

fichier="${1:-}"
prefixe="${GRR_PREFIXE:-NAR_}"

if [ -z "$fichier" ] || [ ! -f "$fichier" ]; then
  echo "✘ Donner le chemin de la sauvegarde : bash local/charger-grr.sh contexte/<fichier>.sql.gz" >&2
  exit 1
fi
# Dépôt public : refuser une sauvegarde qui pourrait être commise par erreur.
if ! git check-ignore -q "$fichier"; then
  echo "✘ $fichier n'est pas ignoré par git. Le ranger dans contexte/ (privé), puis relancer." >&2
  exit 1
fi
if ! docker compose exec -T site test -f artisan 2>/dev/null; then
  echo "✘ Le site local ne tourne pas : lancer d'abord « docker compose up » dans un autre Terminal." >&2
  exit 1
fi
if ! docker compose exec -T site grep -q '^MAIL_HOST=mailpit' .env; then
  echo "✘ Les e-mails locaux ne vont pas dans Mailpit : relancer « docker compose up » avec la dernière version du site." >&2
  exit 1
fi

lire() {
  case "$fichier" in
    *.gz) gunzip -c "$fichier" ;;
    *) cat "$fichier" ;;
  esac
}

echo "▶ Base locale vidée (données fictives effacées)"
docker compose exec -T site php artisan db:wipe --force

echo "▶ Chargement de la sauvegarde (quelques secondes à une minute)"
# Une sauvegarde OVH peut nommer sa base d'origine : on reste dans la base locale.
lire | sed -E '/^(CREATE DATABASE|USE) /d' | docker compose exec -T mysql mysql --default-character-set=utf8mb4 chamrousse

nombre=$(docker compose exec -T mysql mysql -N chamrousse -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = 'chamrousse' AND table_name LIKE '$(printf '%s' "$prefixe" | sed 's/_/\\\\_/g')%'" | tr -d '[:space:]')
if [ "${nombre:-0}" = "0" ]; then
  echo "✘ Aucune table ${prefixe}… dans la sauvegarde. Préfixe différent ? Relancer avec GRR_PREFIXE=xxx_ devant la commande." >&2
  exit 1
fi
echo "  $nombre tables GRR trouvées (préfixe $prefixe)"

echo "▶ Réglages de la reprise dans .env (local, jamais commis)"
docker compose exec -T site sed -i "s|^GRR_PREFIXE=.*|GRR_PREFIXE=${prefixe}|; s|^GRR_REPRISE=.*|GRR_REPRISE=ouverte|" .env

echo "▶ Tables du nouveau site, lignées, puis reprise de GRR"
docker compose exec -T site php artisan migrate --force
docker compose exec -T site php artisan db:seed --force
docker compose exec -T site php artisan grr:importer
docker compose exec -T site touch storage/app/.donnees-grr
docker compose exec -T site rm -f storage/app/.donnees-fictives

echo "✔ Copie de la base chargée : http://localhost:8000 (se connecter avec son compte GRR)."
echo "  E-mails : http://localhost:8025. Revenir aux données fictives : REINITIALISER=oui docker compose up"

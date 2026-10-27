#!/usr/bin/env bash
# Déploiement sur l'hébergement OVH Starter, sans SSH (RFC « Déploiement et bascule »).
# Lancé uniquement par le job « deploiement » après approbation d'un admin.
#
# Arborescence distante ($DOSSIER_DISTANT, par ex. narbonnaise-v2) :
#   releases/<sha>/   versions envoyées
#   actuelle/         version servie (le domaine pointe sur actuelle/public)
#   precedente/       version de retour arrière
#   partage/.env, partage/storage/   jamais écrasés
#
# Deux modes (variable SITE_SERVI de l'environnement GitHub « production ») :
#   non  préparation, avant la bascule : l'offre Starter n'a qu'un site, qui sert
#        encore GRR depuis www/. On envoie et on renomme, sans maintenance,
#        migration ni test de fumée (rien n'est servi).
#   oui  production, après la bascule : séquence complète.
#
# REPRISE=oui (nuit de répétition ou de bascule) : après les migrations, import de GRR
# et rapport de contrôle par /_deploiement/reprise ; un rapport non validé (409)
# déclenche le retour arrière. Voir deploiement/BASCULE.md.
#
# Prérequis du runner : lftp, curl, openssl.
set -euo pipefail

: "${SFTP_HOTE:?}" "${SFTP_UTILISATEUR:?}" "${SFTP_CLE:?}" "${SFTP_EMPREINTE_HOTE:?}"
: "${SITE_URL:?}" "${DOSSIER_DISTANT:?}"
SITE_SERVI="${SITE_SERVI:-non}"
REPRISE="${REPRISE:-non}"

SHA="${GITHUB_SHA:-$(git rev-parse HEAD)}"
SHA="${SHA:0:12}"
TMP="$(mktemp -d)"

journal() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }

# US-804-P3 : durée de chaque étape, reportée à la fin (et dans le résumé GitHub).
DEBUT_ETAPE=$SECONDS; ETAPE="démarrage"; DUREES=""
etape() {
  DUREES="${DUREES}${ETAPE} : $((SECONDS - DEBUT_ETAPE)) s"$'\n'
  ETAPE="$1"; DEBUT_ETAPE=$SECONDS
  journal "$1"
}
bilan() {
  etape "fin"
  printf '\nDurées :\n%s' "$DUREES"
  [ -n "${GITHUB_STEP_SUMMARY:-}" ] && printf '### Durées du déploiement\n```\n%s```\n' "$DUREES" >> "$GITHUB_STEP_SUMMARY" || true
}
trap 'bilan; rm -rf "$TMP"' EXIT

# Clé SFTP et hôte connu : pas de confiance aveugle à la première connexion.
install -m 600 /dev/null "$TMP/cle"
printf '%s\n' "$SFTP_CLE" > "$TMP/cle"
printf '%s\n' "$SFTP_EMPREINTE_HOTE" > "$TMP/known_hosts"
export LFTP_SSH="ssh -i $TMP/cle -o UserKnownHostsFile=$TMP/known_hosts -o StrictHostKeyChecking=yes"

sftp_cmd() {
  lftp -c "set sftp:connect-program \"$LFTP_SSH -a -x\"; open -u $SFTP_UTILISATEUR, sftp://$SFTP_HOTE; $*"
}

# 1. Jeton de migration à usage unique : seule son empreinte part sur le serveur.
JETON="$(openssl rand -hex 32)"
mkdir -p "$TMP/verrou"
printf '%s' "$JETON" | sha256sum | cut -d' ' -f1 > "$TMP/verrou/migration.verrou"
JETON_REPRISE="$(openssl rand -hex 32)"
printf '%s' "$JETON_REPRISE" | sha256sum | cut -d' ' -f1 > "$TMP/verrou/reprise.verrou"

# 2. Sauvegarde de la base avant toute chose (spatie/laravel-backup via la cron ou l'admin).
etape "Vérification de la sauvegarde du jour"
curl -fsS "$SITE_URL/sante" > /dev/null || journal "Avertissement : /sante ne répond pas avant déploiement"

# 3. Envoi de la version dans releases/<sha>/, sans .env ni storage/.
etape "Envoi de la version $SHA"
sftp_cmd "mkdir -p $DOSSIER_DISTANT/releases/$SHA; \
  mirror -R --parallel=4 --exclude-glob .git/ --exclude-glob node_modules/ --exclude-glob tests/ \
         --exclude-glob storage/ --exclude-glob .env ./ $DOSSIER_DISTANT/releases/$SHA/; \
  put $TMP/verrou/migration.verrou -o $DOSSIER_DISTANT/partage/storage/app/deploiement/migration.verrou; \
  $( [ "$REPRISE" = "oui" ] && echo "put $TMP/verrou/reprise.verrou -o $DOSSIER_DISTANT/partage/storage/app/deploiement/reprise.verrou" )"

if [ "$SITE_SERVI" != "oui" ]; then
  journal "Mode préparation : renommages seulement, le site sert encore GRR"
  sftp_cmd "rm -rf $DOSSIER_DISTANT/precedente; \
    mv $DOSSIER_DISTANT/actuelle $DOSSIER_DISTANT/precedente; \
    mv $DOSSIER_DISTANT/releases/$SHA $DOSSIER_DISTANT/actuelle; \
    rm -f $DOSSIER_DISTANT/partage/storage/app/deploiement/migration.verrou"
  journal "Version $SHA déposée dans $DOSSIER_DISTANT/actuelle (non servie)"
  exit 0
fi

# 4. Bascule : maintenance, puis renommages (atomiques côté serveur).
etape "Bascule"
sftp_cmd "put /dev/null -o $DOSSIER_DISTANT/partage/storage/framework/down; \
  rm -rf $DOSSIER_DISTANT/precedente; \
  mv $DOSSIER_DISTANT/actuelle $DOSSIER_DISTANT/precedente; \
  mv $DOSSIER_DISTANT/releases/$SHA $DOSSIER_DISTANT/actuelle"

retour_arriere() {
  journal "ÉCHEC : retour à la version précédente"
  sftp_cmd "mv $DOSSIER_DISTANT/actuelle $DOSSIER_DISTANT/releases/$SHA-echec; \
    mv $DOSSIER_DISTANT/precedente $DOSSIER_DISTANT/actuelle; \
    rm -f $DOSSIER_DISTANT/partage/storage/framework/down"
  exit 1
}

# 5. Migrations par la route protégée (le verrou se supprime après usage).
etape "Migrations"
curl -fsS -X POST -H "Authorization: Bearer $JETON" "$SITE_URL/_deploiement/migrer" || retour_arriere

# 5 bis. Reprise de GRR et contrôle (nuit de répétition ou de bascule). La réponse ne
# contient que des totaux (dépôt public) ; 409 = rapport non validé = retour arrière.
if [ "$REPRISE" = "oui" ]; then
  etape "Reprise de GRR et contrôle"
  curl -sS -X POST -H "Authorization: Bearer $JETON_REPRISE" -o "$TMP/reprise.json" -w '%{http_code}' \
    "$SITE_URL/_deploiement/reprise" > "$TMP/reprise.code" || true
  cat "$TMP/reprise.json"; echo
  [ "$(cat "$TMP/reprise.code")" = "200" ] || { journal "Rapport de reprise NON validé"; retour_arriere; }
fi

# 6. Fin de maintenance et test de fumée.
sftp_cmd "rm -f $DOSSIER_DISTANT/partage/storage/framework/down"
etape "Test de fumée"
for tentative in 1 2 3; do
  if curl -fsS "$SITE_URL/sante" > /dev/null \
     && curl -fsSI "$SITE_URL/login" | grep -qi '^content-security-policy:' \
     && curl -fsSI "$SITE_URL/login" | grep -qi '^strict-transport-security:'; then
    journal "Version $SHA en production"
    exit 0
  fi
  sleep 10
done

# 7. Échec du test de fumée : retour arrière. Aucune migration n'est annulée.
retour_arriere

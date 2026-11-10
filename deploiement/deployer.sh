#!/usr/bin/env bash
# Déploiement sur l'hébergement OVH Starter, sans SSH (RFC « Déploiement et bascule »).
# Lancé uniquement par le job « deploiement » après approbation d'un admin.
#
# Arborescence distante ($DOSSIER_DISTANT, par ex. narbonnaise-v2) :
#   releases/<sha>/   versions envoyées
#   actuelle/         version servie (le domaine pointe sur actuelle/public)
#   precedente/       version de retour arrière
#   releases/corbeille-*  anciennes versions écartées, effacées à la fin (supprimer une version
#                     fichier par fichier en SFTP prend plus de dix minutes : jamais au milieu)
#   partage/.env, partage/storage/   jamais écrasés
#
# Deux modes (variable SITE_SERVI de l'environnement GitHub « production ») :
#   non  préparation, avant la bascule : l'offre Starter n'a qu'un site, qui sert
#        encore GRR depuis www/. On envoie et on renomme, sans maintenance,
#        migration ni test de fumée (rien n'est servi).
#   oui  production, après la bascule : séquence complète.
#
# Prérequis du runner : lftp, curl, openssl.
set -euo pipefail

: "${SFTP_HOTE:?}" "${SFTP_UTILISATEUR:?}" "${SFTP_CLE:?}" "${SFTP_EMPREINTE_HOTE:?}"
: "${SITE_URL:?}" "${DOSSIER_DISTANT:?}"
SITE_SERVI="${SITE_SERVI:-non}"
# « oui » les nuits de répétition et de bascule : reprise de GRR juste après les migrations.
REPRISE_GRR="${REPRISE_GRR:-non}"

SHA="${GITHUB_SHA:-$(git rev-parse HEAD)}"
SHA="${SHA:0:12}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

journal() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }

# Clé SFTP et hôte connu : pas de confiance aveugle à la première connexion.
install -m 600 /dev/null "$TMP/cle"
printf '%s\n' "$SFTP_CLE" > "$TMP/cle"
printf '%s\n' "$SFTP_EMPREINTE_HOTE" > "$TMP/known_hosts"
export LFTP_SSH="ssh -i $TMP/cle -o UserKnownHostsFile=$TMP/known_hosts -o StrictHostKeyChecking=yes"

# SFTP passe par SSH (clé dédiée, empreinte du serveur vérifiée) : rien ne circule en clair.
# DELAI=<secondes> sftp_cmd … : commande interrompue au-delà du délai (nettoyage seulement).
sftp_cmd() {
  ${DELAI:+timeout "$DELAI"} lftp -c "set sftp:connect-program \"$LFTP_SSH -a -x\"; open -u $SFTP_UTILISATEUR, sftp://$SFTP_HOTE; $*" # NOSONAR
}

# 1. Jeton de migration à usage unique : seule son empreinte part sur le serveur.
JETON="$(openssl rand -hex 32)"
mkdir -p "$TMP/verrou"
printf '%s' "$JETON" | sha256sum | cut -d' ' -f1 > "$TMP/verrou/migration.verrou"

# 2. Sauvegarde de la base avant toute chose (spatie/laravel-backup via la cron ou l'admin).
journal "Vérification de la sauvegarde du jour"
curl -fsS "$SITE_URL/sante" > /dev/null || journal "Avertissement : /sante ne répond pas avant déploiement"

# Effacement des versions écartées, après la mise en place : limité à 8 minutes, il reprend au
# déploiement suivant s'il n'a pas fini. Un échec ici ne fait jamais échouer le déploiement.
nettoyer() {
  journal "Nettoyage des anciennes versions (8 minutes au plus)"
  DELAI=480 sftp_cmd "glob -a rm -rf $DOSSIER_DISTANT/releases/corbeille-*" > /dev/null 2>&1 \
    || journal "Nettoyage inachevé : il reprendra au prochain déploiement"
}

# 3. Envoi de la version dans releases/<sha>/, sans .env ni storage/.
#    Sans SSH, l'envoi complet se fait fichier par fichier (une vingtaine de minutes). Quand la
#    version de retour (precedente/) porte son manifeste (empreinte de chaque fichier), on la
#    reprend comme base et on n'envoie que les fichiers changés ; precedente/ est recréée plus
#    bas à partir d'actuelle/, le retour arrière reste possible.
MANIFESTE=.manifeste-deploiement
manifeste() {
  find . -type f ! -path './.git/*' ! -path './node_modules/*' ! -path './tests/*' \
    ! -path './storage/*' ! -name .env ! -name "$MANIFESTE" -print0 | LC_ALL=C sort -z | xargs -0 sha256sum
}
manifeste > "$TMP/manifeste.nouveau"
# Base : precedente/, sinon une version complète restée dans releases/ (envoi interrompu
# après le dépôt du manifeste). Ni la corbeille ni une version en échec ne servent de base.
BASE=""
for candidat in precedente $(sftp_cmd "cls -1 $DOSSIER_DISTANT/releases/" 2> /dev/null \
    | sed 's#/*$##; s#.*/##' | grep -Ev '^(corbeille-|$)|-echec$' || true); do
  [[ "$candidat" = precedente ]] || candidat="releases/$candidat"
  if sftp_cmd "get $DOSSIER_DISTANT/$candidat/$MANIFESTE -o $TMP/manifeste.base" > /dev/null 2>&1 \
     && [[ -s "$TMP/manifeste.base" ]]; then
    BASE="$candidat"
    break
  fi
  rm -f "$TMP/manifeste.base"
done

if [[ -n "$BASE" ]]; then
  # Chemins nouveaux ou modifiés (empreinte différente), puis chemins disparus.
  LC_ALL=C comm -13 <(LC_ALL=C sort "$TMP/manifeste.base") <(LC_ALL=C sort "$TMP/manifeste.nouveau") \
    | cut -c67- > "$TMP/a-envoyer"
  LC_ALL=C comm -23 <(cut -c67- "$TMP/manifeste.base" | LC_ALL=C sort) <(cut -c67- "$TMP/manifeste.nouveau" | LC_ALL=C sort) \
    > "$TMP/a-retirer"
  journal "Envoi de la version $SHA à partir de $BASE : $(wc -l < "$TMP/a-envoyer") fichiers changés, $(wc -l < "$TMP/a-retirer") retirés"
  {
    echo "set cmd:fail-exit yes"
    [[ "$BASE" = "releases/$SHA" ]] || echo "mv $DOSSIER_DISTANT/$BASE $DOSSIER_DISTANT/releases/$SHA"
    echo "rm -f $DOSSIER_DISTANT/releases/$SHA/$MANIFESTE"
    while IFS= read -r f; do echo "rm -f \"$DOSSIER_DISTANT/releases/$SHA/${f#./}\""; done < "$TMP/a-retirer"
    while IFS= read -r f; do dirname "${f#./}"; done < "$TMP/a-envoyer" | LC_ALL=C sort -u \
      | while IFS= read -r d; do echo "mkdir -p \"$DOSSIER_DISTANT/releases/$SHA/$d\""; done
    while IFS= read -r f; do echo "put \"$f\" -o \"$DOSSIER_DISTANT/releases/$SHA/${f#./}\""; done < "$TMP/a-envoyer"
  } > "$TMP/envoi.lftp"
  sftp_cmd "source $TMP/envoi.lftp"
else
  journal "Envoi de la version $SHA (complet : pas de manifeste sur le serveur)"
  # Un envoi complet interrompu laisse un dossier partiel : on le met à la corbeille.
  sftp_cmd "mv $DOSSIER_DISTANT/releases/$SHA $DOSSIER_DISTANT/releases/corbeille-envoi-$SHA-$(date -u +%s)" > /dev/null 2>&1 || true
  sftp_cmd "mkdir -p $DOSSIER_DISTANT/releases/$SHA; \
    mirror -R --parallel=4 --exclude-glob .git/ --exclude-glob node_modules/ --exclude-glob tests/ \
           --exclude-glob storage/ --exclude-glob .env ./ $DOSSIER_DISTANT/releases/$SHA/"
fi
# Le manifeste n'est déposé qu'une fois la version complète : il sert de base au prochain envoi.
sftp_cmd "put $TMP/manifeste.nouveau -o $DOSSIER_DISTANT/releases/$SHA/$MANIFESTE; \
  put $TMP/verrou/migration.verrou -o $DOSSIER_DISTANT/partage/storage/app/deploiement/migration.verrou"

if [[ "$SITE_SERVI" != "oui" ]]; then
  journal "Mode préparation : renommages seulement, le site sert encore GRR"
  sftp_cmd "mv $DOSSIER_DISTANT/precedente $DOSSIER_DISTANT/releases/corbeille-$SHA-$(date -u +%s); \
    mv $DOSSIER_DISTANT/actuelle $DOSSIER_DISTANT/precedente; \
    mv $DOSSIER_DISTANT/releases/$SHA $DOSSIER_DISTANT/actuelle; \
    rm -f $DOSSIER_DISTANT/partage/storage/app/deploiement/migration.verrou"
  journal "Version $SHA déposée dans $DOSSIER_DISTANT/actuelle (non servie)"
  nettoyer
  exit 0
fi

# 4. Bascule : maintenance, puis renommages (atomiques côté serveur).
journal "Bascule"
: > "$TMP/down"
sftp_cmd "put $TMP/down -o $DOSSIER_DISTANT/partage/storage/framework/down; \
  mv $DOSSIER_DISTANT/precedente $DOSSIER_DISTANT/releases/corbeille-$SHA-$(date -u +%s); \
  mv $DOSSIER_DISTANT/actuelle $DOSSIER_DISTANT/precedente; \
  mv $DOSSIER_DISTANT/releases/$SHA $DOSSIER_DISTANT/actuelle"

retour_arriere() {
  journal "ÉCHEC : retour à la version précédente"
  sftp_cmd "mv $DOSSIER_DISTANT/actuelle $DOSSIER_DISTANT/releases/$SHA-echec; \
    mv $DOSSIER_DISTANT/precedente $DOSSIER_DISTANT/actuelle; \
    rm -f $DOSSIER_DISTANT/partage/storage/framework/down"
  exit 1
}

# Nuits de répétition et de bascule (US-805-N1) : le dossier racine OVH pointe déjà sur le
# nouveau site, changé à la main. En cas d'échec, pas de renommage : le site reste en
# maintenance et l'admin remet le dossier racine sur www, où GRR, intact, est de nouveau servi.
# Aucune étape ne touche au fichier de configuration PHP de la racine du compte (US-805-N4).
arret_bascule() {
  echo "::error title=Bascule arrêtée::$1 Le site reste en maintenance. Retour arrière : OVH → Multisite → dossier racine www (GRR est de nouveau servi), puis variables SITE_SERVI=non et REPRISE_GRR=non."
  exit 1
}

# 5. Migrations par la route protégée (le verrou se supprime après usage). Avec la reprise,
#    la route sauvegarde la base et exporte GRR (chiffrés, vérifiés) avant de migrer.
journal "Migrations"
if [[ "$REPRISE_GRR" = "oui" ]]; then
  journal "Reprise de GRR demandée : sauvegarde, export, migrations, import, contrôle"
  REPONSE="$(curl -fsS --max-time 600 -X POST -H "Authorization: Bearer $JETON" "$SITE_URL/_deploiement/migrer?reprise=1")" \
    || arret_bascule "La sauvegarde, l'export de GRR ou les migrations ont échoué."
  journal "Réponse : $REPONSE"
  printf '%s' "$REPONSE" | grep -q '"valide":true' \
    || arret_bascule "Le contrôle de la reprise n'est pas validé (réponse ci-dessus)."
else
  REPONSE="$(curl -fsS --max-time 600 -X POST -H "Authorization: Bearer $JETON" "$SITE_URL/_deploiement/migrer")" || retour_arriere
  journal "Réponse : $REPONSE"
fi

# 6. Fin de maintenance et test de fumée.
sftp_cmd "rm -f $DOSSIER_DISTANT/partage/storage/framework/down"
journal "Test de fumée"
for tentative in 1 2 3; do
  if curl -fsS "$SITE_URL/sante" > /dev/null \
     && curl -fsSI "$SITE_URL/login" | grep -qi '^content-security-policy:' \
     && curl -fsSI "$SITE_URL/login" | grep -qi '^strict-transport-security:'; then
    journal "Version $SHA en production"
    nettoyer
    exit 0
  fi
  sleep 10
done

# 7. Échec du test de fumée : retour arrière. Aucune migration n'est annulée.
retour_arriere

#!/usr/bin/env bash
#
# Déploiement FTPS du plugin tcm-adherents vers la PROD (www.tcmimet.fr, Plesk).
#
#   Configurer UNE FOIS dans le .env NON versionné (à côté du script) :
#     TCM_FTP_HOST_PROD=titan.zwa.fr
#     TCM_FTP_USER_PROD=ftp_xxx.tcmimet          # compte FTP de la PROD (voir Plesk)
#     TCM_FTP_PASS_PROD=le_mot_de_passe_prod
#     TCM_FTP_DIR_PROD=/httpdocs/wp-content/plugins/tcm-adherents
#
#   Usage :
#     bash deploy-prod.sh          # demande une confirmation (site EN LIGNE)
#     bash deploy-prod.sh --yes    # sans confirmation
#
#   Prérequis : lftp   (macOS : brew install lftp)
#   Les mots de passe ne sont JAMAIS dans ce fichier ni dans le dépôt.
#
set -euo pipefail

LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"

# Charge le .env local (doit contenir les TCM_FTP_*_PROD).
[ -f "$LOCAL_DIR/.env" ] && set -a && . "$LOCAL_DIR/.env" && set +a

HOST="${TCM_FTP_HOST_PROD:-titan.zwa.fr}"
USER="${TCM_FTP_USER_PROD:?Définis TCM_FTP_USER_PROD (compte FTP prod) dans .env}"
PASS="${TCM_FTP_PASS_PROD:?Définis TCM_FTP_PASS_PROD (mot de passe FTP prod) dans .env}"
REMOTE_DIR="${TCM_FTP_DIR_PROD:-/httpdocs/wp-content/plugins/tcm-adherents}"

# Garde-fou : la PROD est le site EN LIGNE.
if [ "${1:-}" != "--yes" ]; then
  echo "⚠️  Déploiement sur la PROD (www.tcmimet.fr) — site EN LIGNE."
  read -r -p "   Confirmer ? Tape 'PROD' : " ans
  [ "$ans" = "PROD" ] || { echo "Annulé."; exit 1; }
fi

echo "→ Déploiement PROD : $LOCAL_DIR  →  ftps://$HOST$REMOTE_DIR"

lftp -u "$USER,$PASS" "ftp://$HOST" <<EOF
set ftp:ssl-force true
set ftp:ssl-protect-data true
set ftp:passive-mode true
set ssl:verify-certificate no
set net:max-retries 2
set net:timeout 15
mirror --reverse --delete --verbose \
  --exclude-glob .git/ \
  --exclude-glob .vscode/ \
  --exclude-glob '*.zip' \
  --exclude-glob node_modules/ \
  --exclude-glob '.env*' \
  --exclude-glob 'deploy*.sh' \
  --exclude-glob 'import-data/' \
  "$LOCAL_DIR/" "$REMOTE_DIR/"
bye
EOF

echo "✅ Déployé sur la PROD."
echo "ℹ️  OPcache : si un changement PHP ne 'prend' pas tout de suite, vide le cache PHP"
echo "   dans Plesk (le dev avait opcache.revalidate_freq=0, pas forcément la prod)."

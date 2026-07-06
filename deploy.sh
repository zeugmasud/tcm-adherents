#!/usr/bin/env bash
#
# Déploiement FTPS du plugin tcm-adherents vers le dev (Plesk).
#
#   Usage :
#     export TCM_FTP_PASS='le_mot_de_passe_ftp'   # ou via un .env NON versionné
#     ./deploy.sh
#
#   Prérequis : lftp
#     macOS : brew install lftp
#     Linux (VM Cowork) : apt-get install -y lftp
#
#   Le mot de passe n'est JAMAIS dans ce fichier ni dans le dépôt.
#
set -euo pipefail

HOST="titan.zwa.fr"
USER="ftp_dev.tcmimet"
REMOTE_DIR="/httpdocs/wp-content/plugins/tcm-adherents"
LOCAL_DIR="$(cd "$(dirname "$0")" && pwd)"

# Charge un .env local s'il existe (doit contenir : TCM_FTP_PASS=...)
[ -f "$LOCAL_DIR/.env" ] && set -a && . "$LOCAL_DIR/.env" && set +a

: "${TCM_FTP_PASS:?Définis TCM_FTP_PASS (mot de passe FTP) avant de lancer — export ou .env}"

echo "→ Déploiement : $LOCAL_DIR  →  ftps://$HOST$REMOTE_DIR"

lftp -u "$USER,$TCM_FTP_PASS" "ftp://$HOST" <<EOF
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
  --exclude-glob deploy.sh \
  "$LOCAL_DIR/" "$REMOTE_DIR/"
bye
EOF

echo "✅ Déployé. (OPcache dev revalidé immédiatement → changements visibles tout de suite.)"

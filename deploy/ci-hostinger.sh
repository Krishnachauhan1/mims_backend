#!/usr/bin/env bash
# Hostinger backend deploy — GitHub Actions + local Mac.
#
# GitHub: .github/workflows/deploy.yml calls: sync, then SSH postdeploy on server.
#
# Local (SSH key):
#   export SSH_HOST=145.79.58.104 SSH_USERNAME=u683672103 SSH_PORT=65002
#   export REMOTE_DIR=domains/powerforceindustries.com/public_html
#   export RSYNC_RSH="ssh -p $SSH_PORT -i ~/.ssh/mims_github_deploy"
#   ./deploy/ci-hostinger.sh full
#
# Local (password — needs sshpass: brew install hudochenkov/sshpass/sshpass):
#   export SSHPASS='your-ssh-password'
#   export SSH_HOST=... SSH_USERNAME=... SSH_PORT=65002
#   ./deploy/ci-hostinger.sh full
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ACTION="${1:-full}"

SSH_HOST="${SSH_HOST:-145.79.58.104}"
SSH_USERNAME="${SSH_USERNAME:-u683672103}"
SSH_PORT="${SSH_PORT:-65002}"
REMOTE_DIR="${REMOTE_DIR:-domains/powerforceindustries.com/public_html}"

resolve_rsync_rsh() {
  if [ -n "${RSYNC_RSH:-}" ]; then
    return
  fi
  if [ -f "${HOME}/.ssh/id_deploy" ]; then
    RSYNC_RSH="ssh -i ${HOME}/.ssh/id_deploy -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new"
  elif [ -n "${SSHPASS:-}" ]; then
    if ! command -v sshpass >/dev/null 2>&1; then
      echo "Install sshpass for password SSH, or set RSYNC_RSH / deploy key." >&2
      exit 1
    fi
    RSYNC_RSH="sshpass -e ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new"
  elif [ -n "${SSH_IDENTITY_FILE:-}" ] && [ -f "${SSH_IDENTITY_FILE}" ]; then
    RSYNC_RSH="ssh -i ${SSH_IDENTITY_FILE} -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new"
  else
    RSYNC_RSH="ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new"
  fi
  export RSYNC_RSH
}

ssh_cmd() {
  resolve_rsync_rsh
  # shellcheck disable=SC2086
  $RSYNC_RSH "${SSH_USERNAME}@${SSH_HOST}" "$@"
}

REMOTE="${SSH_USERNAME}@${SSH_HOST}:~/${REMOTE_DIR}/"

RSYNC_EXCLUDES=(
  --exclude '.git'
  --exclude '.github'
  --exclude 'vendor'
  --exclude 'node_modules'
  --exclude '.env'
  --exclude '.env.*'
  --exclude 'storage/logs/*'
  --exclude 'storage/framework/cache/data/*'
  --exclude 'storage/framework/sessions/*'
  --exclude 'storage/framework/views/*'
  --exclude '.DS_Store'
  --exclude 'public/hot'
)

do_sync() {
  resolve_rsync_rsh
  echo "==> Rsync to ${REMOTE}"
  rsync -avz -e "$RSYNC_RSH" "${RSYNC_EXCLUDES[@]}" "$ROOT/" "$REMOTE"
}

do_postdeploy() {
  echo "==> Composer, migrate, cache on server..."
  ssh_cmd bash -s <<ENDSSH
set -e
cd ~/${REMOTE_DIR}
if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
else
  echo "Composer not in PATH — using existing vendor/ on server"
fi
php artisan migrate --force --no-interaction
php artisan optimize:clear --no-interaction
echo ""
echo "=== API health ==="
curl -4 -s -H "Accept: application/json" https://powerforceindustries.com/api/health | head -c 200 || true
echo ""
ENDSSH
}

case "$ACTION" in
  sync)
    do_sync
    ;;
  postdeploy)
    do_postdeploy
    ;;
  full)
    do_sync
    do_postdeploy
    echo ""
    echo "Done. API: https://powerforceindustries.com/api/health"
    ;;
  *)
    echo "Usage: $0 {sync|postdeploy|full}" >&2
    exit 1
    ;;
esac

#!/usr/bin/env bash
# Hostinger backend deploy — GitHub Actions + local Mac.
#
# GitHub: .github/workflows/deploy.yml calls: sync, then postdeploy on server.
# Upload uses tar over SSH (Hostinger often has no rsync on SSH → rsync exit 255).
#
# Local (SSH key):
#   export SSH_HOST=145.79.58.104 SSH_USERNAME=u683672103 SSH_PORT=65002
#   export REMOTE_DIR=domains/powerforceindustries.com/public_html
#   export RSYNC_RSH="ssh -p $SSH_PORT -i ~/.ssh/mims_github_deploy"
#   ./deploy/ci-hostinger.sh full
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ACTION="${1:-full}"

SSH_HOST="${SSH_HOST:-145.79.58.104}"
SSH_USERNAME="${SSH_USERNAME:-u683672103}"
SSH_PORT="${SSH_PORT:-65002}"
REMOTE_DIR="${REMOTE_DIR-}"

normalize_remote_dir() {
  REMOTE_DIR="$(printf '%s' "${REMOTE_DIR}" | tr -d '\r\n' | sed 's/^[[:space:]]*//;s/[[:space:]]*$//')"
  if [ -z "$REMOTE_DIR" ]; then
    REMOTE_DIR="domains/powerforceindustries.com/public_html"
  elif [ "$REMOTE_DIR" = "public_html" ]; then
    REMOTE_DIR="domains/powerforceindustries.com/public_html"
  fi
}

normalize_remote_dir

if [ "$SSH_PORT" = "21" ]; then
  echo "==> SSH_PORT was 21 (FTP). Using Hostinger SSH port 65002." >&2
  SSH_PORT="65002"
fi

resolve_ssh() {
  if [ -n "${RSYNC_RSH:-}" ]; then
    return
  fi
  if [ -f "${HOME}/.ssh/id_deploy" ]; then
    RSYNC_RSH="ssh -i ${HOME}/.ssh/id_deploy -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 -o IdentitiesOnly=yes"
  elif [ -n "${SSHPASS:-}" ]; then
    if ! command -v sshpass >/dev/null 2>&1; then
      echo "Install sshpass for password SSH, or set RSYNC_RSH / deploy key." >&2
      exit 1
    fi
    RSYNC_RSH="sshpass -e ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30 -o PreferredAuthentications=password -o PubkeyAuthentication=no"
  elif [ -n "${SSH_IDENTITY_FILE:-}" ] && [ -f "${SSH_IDENTITY_FILE}" ]; then
    RSYNC_RSH="ssh -i ${SSH_IDENTITY_FILE} -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30"
  else
    RSYNC_RSH="ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new -o ConnectTimeout=30"
  fi
  export RSYNC_RSH
}

ssh_cmd() {
  resolve_ssh
  # shellcheck disable=SC2086
  $RSYNC_RSH "${SSH_USERNAME}@${SSH_HOST}" "$@"
}

preflight_ssh() {
  echo "==> SSH test ${SSH_USERNAME}@${SSH_HOST}:${SSH_PORT}"
  if ! ssh_cmd "echo ssh-ok"; then
    echo "" >&2
    echo "SSH login failed from this machine." >&2
    echo "GitHub Actions: use secret SSH_PRIVATE_KEY (deploy key on server), not FTP password." >&2
    echo "Or set SSH_PASSWORD to the same password as: ssh -p 65002 ${SSH_USERNAME}@${SSH_HOST}" >&2
    exit 1
  fi
}

TAR_EXCLUDES=(
  --exclude='./.git'
  --exclude='./.github'
  --exclude='./vendor'
  --exclude='./node_modules'
  --exclude='./.env'
  --exclude='./.env.*'
  --exclude='./storage/logs'
  --exclude='./storage/framework/cache/data'
  --exclude='./storage/framework/sessions'
  --exclude='./storage/framework/views'
  --exclude='./.DS_Store'
  --exclude='./public/hot'
  --exclude='./bootstrap/cache/packages.php'
  --exclude='./bootstrap/cache/services.php'
  --exclude='./bootstrap/cache/config.php'
)

do_sync_tar() {
  normalize_remote_dir
  local remote_quoted
  remote_quoted=$(printf '%s' "$REMOTE_DIR" | sed "s/'/'\\\\''/g")
  echo "==> Upload (tar+ssh) → ~/${REMOTE_DIR}/"
  ssh_cmd "mkdir -p '~/${remote_quoted}'"
  tar -czf - "${TAR_EXCLUDES[@]}" -C "$ROOT" . | ssh_cmd "tar -xzf - -C '~/${remote_quoted}'"
}

do_sync_rsync() {
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
    --exclude 'bootstrap/cache/*.php'
  )
  echo "==> Rsync → ${REMOTE}"
  rsync -avz -e "$RSYNC_RSH" "${RSYNC_EXCLUDES[@]}" "$ROOT/" "$REMOTE"
}

do_sync() {
  resolve_ssh
  preflight_ssh
  if [ "${DEPLOY_SYNC:-tar}" = "rsync" ]; then
    do_sync_rsync
  else
    do_sync_tar
  fi
}

do_postdeploy() {
  preflight_ssh
  echo "==> Composer, migrate, cache on server..."
  ssh_cmd bash -s <<ENDSSH
set -e
cd ~/${REMOTE_DIR}
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php
if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
else
  echo "Composer not in PATH — using existing vendor/ on server"
fi
php artisan package:discover --ansi --no-interaction
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

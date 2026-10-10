#!/usr/bin/env bash
# Used by GitHub Actions (.github/workflows/deploy.yml) and optional local dry-run.
# Local example (same as deploy-backend-live, with key auth):
#   export SSH_HOST=145.79.58.104 SSH_USERNAME=u683672103 SSH_PORT=65002
#   export REMOTE_DIR=domains/powerforceindustries.com/public_html
#   export RSYNC_RSH="ssh -p $SSH_PORT -i ~/.ssh/mims_github_deploy"
#   bash deploy/ci-hostinger.sh sync
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ACTION="${1:-sync}"

SSH_HOST="${SSH_HOST:?SSH_HOST required}"
SSH_USERNAME="${SSH_USERNAME:?SSH_USERNAME required}"
SSH_PORT="${SSH_PORT:-65002}"
REMOTE_DIR="${REMOTE_DIR:-domains/powerforceindustries.com/public_html}"

if [ -n "${RSYNC_RSH:-}" ]; then
  :
elif [ -n "${SSH_PRIVATE_KEY:-}" ] && [ -f "${HOME}/.ssh/id_deploy" ]; then
  RSYNC_RSH="ssh -i ${HOME}/.ssh/id_deploy -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new"
elif [ -n "${SSHPASS:-}" ]; then
  if ! command -v sshpass >/dev/null 2>&1; then
    echo "sshpass required when using SSHPASS" >&2
    exit 1
  fi
  RSYNC_RSH="sshpass -e ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new"
else
  RSYNC_RSH="ssh -p ${SSH_PORT} -o StrictHostKeyChecking=accept-new"
fi

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

case "$ACTION" in
  sync)
    echo "==> Rsync to ${REMOTE}"
    rsync -avz -e "$RSYNC_RSH" "${RSYNC_EXCLUDES[@]}" "$ROOT/" "$REMOTE"
    ;;
  *)
    echo "Usage: $0 sync" >&2
    exit 1
    ;;
esac

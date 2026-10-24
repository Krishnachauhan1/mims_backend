#!/usr/bin/env bash
# Incremental backend deploy → powerforceindustries.com Laravel API (Hostinger).
# Run on your Mac (SSH password when prompted).
set -euo pipefail

SSH_HOST="u683672103@145.79.58.104"
SSH_PORT="65002"
REMOTE="domains/powerforceindustries.com/public_html"
ROOT="$(cd "$(dirname "$0")" && pwd)"
RSYNC_SSH="ssh -p $SSH_PORT"

echo "==> Upload app/ (controllers, Console commands, models, services, support)..."
rsync -avz -e "$RSYNC_SSH" \
  --exclude '.DS_Store' \
  "$ROOT/app/" "$SSH_HOST:~/$REMOTE/app/"

echo "==> Upload routes + config..."
scp -P "$SSH_PORT" \
  "$ROOT/routes/api.php" \
  "$SSH_HOST:~/$REMOTE/routes/api.php"
scp -P "$SSH_PORT" \
  "$ROOT/config/app_config.json" \
  "$SSH_HOST:~/$REMOTE/config/app_config.json"

echo "==> Upload migrations..."
rsync -avz -e "$RSYNC_SSH" \
  "$ROOT/database/migrations/" "$SSH_HOST:~/$REMOTE/database/migrations/"

echo "==> Migrate + clear cache on server..."
ssh -p "$SSH_PORT" "$SSH_HOST" << 'ENDSSH'
set -e
cd ~/domains/powerforceindustries.com/public_html
php artisan migrate --force
php artisan optimize:clear
echo ""
echo "=== production:* artisan commands ==="
php artisan list production 2>/dev/null | grep -E 'repair-partial|remind-pending' || true
echo ""
echo "=== API health ==="
curl -4 -s -o /dev/null -w "health: HTTP %{http_code}\n" -H "Accept: application/json" https://powerforceindustries.com/api/health || true
curl -4 -s -H "Accept: application/json" https://powerforceindustries.com/api/health | head -c 120 || true
echo ""
ENDSSH

echo ""
echo "Done. Backend live at https://powerforceindustries.com/api/health"

#!/usr/bin/env bash
# Fix dashboard low-stock alerts (aggregate by material, not per empty batch).
set -euo pipefail

SSH="u683672103@145.79.58.104"
PORT=65002
REMOTE="domains/powerforceindustries.com/public_html"
ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "==> Upload DashboardController (API)..."
scp -P "$PORT" \
  "$ROOT/app/Http/Controllers/Api/DashboardController.php" \
  "$SSH:~/$REMOTE/app/Http/Controllers/Api/"

echo "==> Clear cache..."
ssh -p "$PORT" "$SSH" << 'ENDSSH'
cd ~/domains/powerforceindustries.com/public_html
php artisan optimize:clear
echo "(API smoke test needs login token — Unauthenticated below is OK)"
curl -4 -s https://powerforceindustries.com/api/dashboard/low-stock-alerts -H "Accept: application/json" | head -c 200
echo ""
ENDSSH

echo "Done. Refresh dashboard in browser."

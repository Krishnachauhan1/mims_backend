#!/usr/bin/env bash
# Fix Hostinger /api/* 404 (htaccess + bootstrap) and deploy Job Work bundle API.
set -euo pipefail

SSH_HOST="u683672103@145.79.58.104"
SSH_PORT="65002"
REMOTE="domains/powerforceindustries.com/public_html"
ROOT="$(cd "$(dirname "$0")" && pwd)"
SSH="ssh -p $SSH_PORT $SSH_HOST"
RSYNC_SSH="ssh -p $SSH_PORT"

echo "==> Upload API bootstrap + .htaccess (fixes HTML 404 on /api/*)..."
scp -P "$SSH_PORT" \
  "$ROOT/deploy/hostinger/laravel-bootstrap.php" \
  "$ROOT/deploy/hostinger/laravel-api-index.php" \
  "$ROOT/deploy/hostinger/public_html.htaccess" \
  "$SSH_HOST:~/$REMOTE/"

$SSH "cp ~/$REMOTE/public_html.htaccess ~/$REMOTE/.htaccess"

echo "==> Upload mims subdomain API proxy..."
$SSH "mkdir -p ~/domains/mims.powerforceindustries.com/public_html"
FLUTTER_ROOT="$(cd "$ROOT/../manufacring-and-inventory-management" && pwd)"
scp -P "$SSH_PORT" \
  "$ROOT/deploy/hostinger/mims-laravel-api-index.php" \
  "$SSH_HOST:~/domains/mims.powerforceindustries.com/public_html/laravel-api-index.php"
scp -P "$SSH_PORT" \
  "$FLUTTER_ROOT/deploy/hostinger/mims-flutter.htaccess" \
  "$SSH_HOST:~/domains/mims.powerforceindustries.com/public_html/.htaccess"

echo "==> Upload Job Work bundle API..."
scp -P "$SSH_PORT" \
  "$ROOT/app/Http/Controllers/Api/JobWorkController.php" \
  "$SSH_HOST:~/$REMOTE/app/Http/Controllers/Api/JobWorkController.php"
scp -P "$SSH_PORT" \
  "$ROOT/routes/api.php" \
  "$SSH_HOST:~/$REMOTE/routes/api.php"

echo "==> Clear caches + verify..."
$SSH << ENDSSH
set -e
cd ~/$REMOTE
php artisan route:clear
php artisan optimize:clear
echo ""
echo "=== Files on server ==="
ls -la .htaccess laravel-api-index.php laravel-bootstrap.php 2>/dev/null || true
echo ""
echo "=== HTTP checks (expect JSON, not HTML) ==="
curl -4 -s -o /dev/null -w "health: HTTP %{http_code}\n" -H "Accept: application/json" https://powerforceindustries.com/api/health
curl -4 -s -H "Accept: application/json" "https://powerforceindustries.com/api/health" | head -c 120
echo ""
curl -4 -s -o /dev/null -w "job-work bundle: HTTP %{http_code}\n" -H "Accept: application/json" "https://powerforceindustries.com/api/job-work?bundle=1&direction=all"
curl -4 -s -H "Accept: application/json" "https://powerforceindustries.com/api/job-work?bundle=1&direction=all" | head -c 120
echo ""
curl -4 -s -o /dev/null -w "mims version.json: HTTP %{http_code}\n" https://mims.powerforceindustries.com/version.json
curl -4 -s -o /dev/null -w "mims api health: HTTP %{http_code}\n" -H "Accept: application/json" https://mims.powerforceindustries.com/api/health
echo ""
ENDSSH

echo ""
echo "Done. Redeploy Flutter: ../manufacring-and-inventory-management/deploy-flutter-web.sh"

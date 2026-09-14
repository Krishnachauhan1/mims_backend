#!/usr/bin/env bash
# Full deploy: Laravel API + MIMS web (Blade) to Hostinger powerforceindustries.com
# Run on your Mac (needs SSH password or key).
set -euo pipefail

SSH_HOST="u683672103@145.79.58.104"
SSH_PORT="65002"
DOMAIN="domains/powerforceindustries.com"
REMOTE_API="$DOMAIN/public_html"
REMOTE_WEB="$DOMAIN/public_html"

ROOT="$(cd "$(dirname "$0")" && pwd)"
SSH="ssh -p $SSH_PORT $SSH_HOST"
RSYNC_SSH="ssh -p $SSH_PORT"

echo "==> Creating remote folders..."
$SSH "mkdir -p ~/$REMOTE_API ~/$REMOTE_WEB/mims"

echo "==> Uploading Laravel backend to public_html..."
rsync -avz --delete \
  -e "$RSYNC_SSH" \
  --exclude '.git' \
  --exclude 'node_modules' \
  --exclude '.env' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/framework/cache/data/*' \
  --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' \
  --exclude 'bootstrap/cache/*.php' \
  "$ROOT/" "$SSH_HOST:~/$REMOTE_API/"

echo "==> Uploading public_html bootstrap + htaccess..."
scp -P "$SSH_PORT" \
  "$ROOT/deploy/hostinger/laravel-bootstrap.php" \
  "$ROOT/deploy/hostinger/laravel-api-index.php" \
  "$ROOT/deploy/hostinger/public_html.htaccess" \
  "$SSH_HOST:~/$REMOTE_WEB/"

$SSH "cp ~/$REMOTE_WEB/public_html.htaccess ~/$REMOTE_WEB/.htaccess"

echo "==> Uploading MIMS web entry (replaces Flutter)..."
scp -P "$SSH_PORT" \
  "$ROOT/deploy/hostinger/mims-web-index.php" \
  "$ROOT/deploy/hostinger/mims-web.htaccess" \
  "$SSH_HOST:~/$REMOTE_WEB/mims/"

$SSH "mv ~/$REMOTE_WEB/mims/mims-web-index.php ~/$REMOTE_WEB/mims/index.php && mv ~/$REMOTE_WEB/mims/mims-web.htaccess ~/$REMOTE_WEB/mims/.htaccess"

echo "==> Removing old Flutter files from mims folder..."
$SSH "cd ~/$REMOTE_WEB/mims && rm -f index.html flutter.js flutter_bootstrap.js main.dart.js laravel-api-index.php manifest.json flutter_service_worker.js version.json && rm -rf canvaskit assets icons 2>/dev/null || true"

echo "==> Copying CSS/assets into mims folder (Hostinger blocks parent rewrites)..."
$SSH "mkdir -p ~/$REMOTE_WEB/mims/css && cp ~/$REMOTE_API/public/css/mims.css ~/$REMOTE_WEB/mims/css/mims.css"

echo "==> Post-deploy on server..."
$SSH << ENDSSH
set -e
cd ~/$REMOTE_API

if [ ! -f .env ]; then
  cp .env.production.powerforce.example .env
  php artisan key:generate --force
fi

# MIMS web session + asset URL
grep -q '^APP_URL=' .env && sed -i 's|^APP_URL=.*|APP_URL=https://mims.powerforceindustries.com|' .env || echo 'APP_URL=https://mims.powerforceindustries.com' >> .env
grep -q '^SESSION_DOMAIN=' .env && sed -i 's|^SESSION_DOMAIN=.*|SESSION_DOMAIN=.powerforceindustries.com|' .env || echo 'SESSION_DOMAIN=.powerforceindustries.com' >> .env
grep -q '^SANCTUM_STATEFUL_DOMAINS=' .env && sed -i 's|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=mims.powerforceindustries.com,powerforceindustries.com,www.powerforceindustries.com|' .env || echo 'SANCTUM_STATEFUL_DOMAINS=mims.powerforceindustries.com,powerforceindustries.com,www.powerforceindustries.com' >> .env

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache

# Remove dev-only cached providers (Pail, Collision, etc.)
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php

if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader --no-scripts 2>/dev/null || composer install --no-dev --optimize-autoloader --no-scripts
fi

php artisan package:discover --ansi || true
php artisan optimize:clear
php artisan migrate --force
ln -sfn "\$(pwd)/storage/app/public" "\$(pwd)/public/storage" 2>/dev/null || true

echo ""
echo "=== Health checks ==="
curl -4 -s -o /dev/null -w "API: %{http_code}\n" https://powerforceindustries.com/api/health || true
curl -4 -s -o /dev/null -w "MIMS login: %{http_code}\n" https://mims.powerforceindustries.com/login || true
ENDSSH

echo ""
echo "Deploy complete."
echo "  API:  https://powerforceindustries.com/api/health"
echo "  Web:  https://mims.powerforceindustries.com/login"

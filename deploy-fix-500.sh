#!/usr/bin/env bash
# Fix 500 after deploy — clears dev bootstrap cache + rebuilds on server.
set -euo pipefail

SSH_HOST="u683672103@145.79.58.104"
SSH_PORT="65002"
REMOTE_API="domains/powerforceindustries.com/public_html"

ssh -p "$SSH_PORT" "$SSH_HOST" << 'ENDSSH'
set -e
cd ~/$REMOTE_API 2>/dev/null || cd ~/domains/powerforceindustries.com/public_html

echo "==> Clearing cached bootstrap (dev providers break production)..."
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php bootstrap/cache/routes*.php

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache

if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader --no-scripts
else
  echo "Composer not found — using uploaded vendor/"
fi

php artisan package:discover --ansi || true
php artisan optimize:clear
php artisan config:clear
php artisan route:clear
php artisan view:clear
php artisan migrate --force || echo "Migrate failed — check DB_PASSWORD in .env"

ln -sfn "$(pwd)/storage/app/public" "$(pwd)/public/storage" 2>/dev/null || true

echo ""
echo "=== Health checks ==="
curl -4 -s https://powerforceindustries.com/api/health || true
echo ""
curl -4 -s -o /dev/null -w "MIMS login: HTTP %{http_code}\n" https://mims.powerforceindustries.com/login || true
ENDSSH

echo "Fix script done."

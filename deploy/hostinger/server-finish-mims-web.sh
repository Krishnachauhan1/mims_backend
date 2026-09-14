#!/usr/bin/env bash
# Run ON THE SERVER after code upload (SSH session).
set -euo pipefail

API="$HOME/domains/powerforceindustries.com/mims-api"
WEB="$HOME/domains/powerforceindustries.com/public_html"

cd "$API"

if [ ! -f .env ]; then
  cp .env.production.powerforce.example .env
  php artisan key:generate --force
  echo ">>> Set DB_PASSWORD in .env before migrate <<<"
fi

grep -q '^APP_URL=' .env && sed -i 's|^APP_URL=.*|APP_URL=https://mims.powerforceindustries.com|' .env || echo 'APP_URL=https://mims.powerforceindustries.com' >> .env
grep -q '^SESSION_DOMAIN=' .env && sed -i 's|^SESSION_DOMAIN=.*|SESSION_DOMAIN=.powerforceindustries.com|' .env || echo 'SESSION_DOMAIN=.powerforceindustries.com' >> .env

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache

rm -f bootstrap/cache/packages.php bootstrap/cache/services.php bootstrap/cache/config.php

if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader --no-scripts || true
fi

php artisan package:discover --ansi || true
php artisan optimize:clear
php artisan migrate --force
ln -sfn "$(pwd)/storage/app/public" "$(pwd)/public/storage" 2>/dev/null || true

# MIMS web bootstrap
mkdir -p "$WEB/mims"
cp "$API/deploy/hostinger/laravel-bootstrap.php" "$WEB/"
cp "$API/deploy/hostinger/laravel-api-index.php" "$WEB/"
cp "$API/deploy/hostinger/public_html.htaccess" "$WEB/.htaccess"
cp "$API/deploy/hostinger/mims-web-index.php" "$WEB/mims/index.php"
cp "$API/deploy/hostinger/mims-web.htaccess" "$WEB/mims/.htaccess"

rm -f "$WEB/mims/index.html" "$WEB/mims/flutter.js" "$WEB/mims/main.dart.js" "$WEB/mims/laravel-api-index.php"
rm -rf "$WEB/mims/canvaskit" "$WEB/mims/assets" "$WEB/mims/icons" 2>/dev/null || true

mkdir -p "$WEB/mims/css"
cp "$API/public/css/mims.css" "$WEB/mims/css/mims.css"

echo ""
curl -4 -s -o /dev/null -w "API health: HTTP %{http_code}\n" https://powerforceindustries.com/api/health || true
curl -4 -s -o /dev/null -w "MIMS login: HTTP %{http_code}\n" https://mims.powerforceindustries.com/login || true
echo "Done."

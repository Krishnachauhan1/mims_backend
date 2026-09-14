#!/usr/bin/env bash
# Deploy MIMS Laravel API to powerforceindustries.com (run on your Mac, NOT inside SSH).
set -euo pipefail

SSH_HOST="u683672103@145.79.58.104"
SSH_PORT="65002"
# Laravel app root — OUTSIDE public_html (recommended)
REMOTE="domains/powerforceindustries.com/public_html"

ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "==> Creating remote folders..."
ssh -p "$SSH_PORT" "$SSH_HOST" "mkdir -p ~/$REMOTE"

echo "==> Uploading backend (excluding vendor, .env, storage logs)..."
rsync -avz --delete \
  -e "ssh -p $SSH_PORT" \
  --exclude '.git' \
  --exclude 'vendor' \
  --exclude 'node_modules' \
  --exclude '.env' \
  --exclude 'storage/logs/*' \
  --exclude 'storage/framework/cache/data/*' \
  --exclude 'storage/framework/sessions/*' \
  --exclude 'storage/framework/views/*' \
  "$ROOT/" "$SSH_HOST:~/$REMOTE/"

echo "==> Post-deploy on server..."
ssh -p "$SSH_PORT" "$SSH_HOST" << ENDSSH
set -e
cd ~/$REMOTE

if [ ! -f .env ]; then
  cp .env.production.powerforce.example .env
  echo ""
  echo ">>> Created .env from example — EDIT DB_PASSWORD and run key:generate before going live <<<"
fi

mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache

if command -v composer >/dev/null 2>&1; then
  composer install --no-dev --optimize-autoloader
else
  echo "Composer not in PATH — upload vendor/ from local or enable composer in hPanel SSH"
fi

php artisan optimize:clear

echo ""
echo "=== Next (once .env DB password is set) ==="
echo "php artisan key:generate"
echo "php artisan migrate --force"
echo "php artisan db:seed --class=AdminSeeder --force"
echo "php artisan storage:link"
echo ""
echo "=== Health (after document root + .env ready) ==="
echo "curl -4 https://powerforceindustries.com/api/health"
ENDSSH

echo "Upload done."

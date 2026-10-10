#!/usr/bin/env bash
# Bootstrap Laravel for local dev (Mac). Run from repo root:
#   ./scripts/setup-local.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

echo "==> Composer install"
composer install --no-interaction

if [[ ! -f .env ]]; then
  echo "==> Creating .env from .env.local.example"
  cp .env.local.example .env
else
  echo "==> .env already exists (not overwritten)"
fi

if ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
  echo "==> Generating APP_KEY"
  php artisan key:generate
fi

echo "==> Storage link"
php artisan storage:link 2>/dev/null || true

echo "==> Migrate (safe on empty DB; skip if you will import live dump)"
read -r -p "Run migrations now? [y/N] " ans
if [[ "${ans,,}" == "y" ]]; then
  php artisan migrate --force
fi

echo ""
echo "Done. Next:"
echo "  1) Import live DB: ./scripts/import-live-database.sh /path/to/dump.sql"
echo "  2) Start API:      php artisan serve --host=127.0.0.1 --port=8000"
echo "  3) Flutter:        use app_config.local.json (debug) or copy to app_config.json"

#!/usr/bin/env bash
# Upload backend fixes to production — run on your Mac (NOT inside SSH).
set -euo pipefail

SSH_HOST="u336943698@82.25.107.211"
SSH_PORT="65002"
REMOTE="domains/nextlogicsolution.id/public_html/mis"

ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "==> Creating folders on server (if missing)..."
ssh -p "$SSH_PORT" "$SSH_HOST" "mkdir -p ~/$REMOTE/app/Providers ~/$REMOTE/app/Http/Controllers/Api ~/$REMOTE/app/Services ~/$REMOTE/app/Models"

echo "==> Uploading AppServiceProvider..."
scp -P "$SSH_PORT" \
  "$ROOT/app/Providers/AppServiceProvider.php" \
  "$SSH_HOST:$REMOTE/app/Providers/"

echo "==> Uploading ImportExportController + ItemController..."
scp -P "$SSH_PORT" \
  "$ROOT/app/Http/Controllers/Api/ImportExportController.php" \
  "$ROOT/app/Http/Controllers/Api/ItemController.php" \
  "$SSH_HOST:$REMOTE/app/Http/Controllers/Api/"

echo "==> Uploading import services..."
scp -P "$SSH_PORT" \
  "$ROOT/app/Services/EntityImportService.php" \
  "$ROOT/app/Services/SpreadsheetService.php" \
  "$SSH_HOST:$REMOTE/app/Services/"

echo "==> Uploading Item model..."
scp -P "$SSH_PORT" \
  "$ROOT/app/Models/Item.php" \
  "$SSH_HOST:$REMOTE/app/Models/"

echo "==> Clearing cache + verify..."
ssh -p "$SSH_PORT" "$SSH_HOST" << 'ENDSSH'
cd ~/domains/nextlogicsolution.id/public_html/mis
composer dump-autoload --no-dev 2>/dev/null || true
php artisan optimize:clear
echo ""
echo "=== Files on server ==="
ls -la app/Http/Controllers/Api/ImportExportController.php
ls -la app/Providers/AppServiceProvider.php
echo ""
echo "=== ENTITIES ==="
grep ENTITIES app/Http/Controllers/Api/ImportExportController.php
echo ""
echo "=== Health ==="
curl -s https://mis.nextlogicsolution.id/api/health
echo ""
ENDSSH

echo "Done."

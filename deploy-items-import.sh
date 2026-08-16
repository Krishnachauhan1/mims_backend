#!/usr/bin/env bash
# Upload items import/export backend files to production.
# Usage: ./deploy-items-import.sh
set -euo pipefail

SSH_HOST="u336943698@82.25.107.211"
SSH_PORT="65002"
REMOTE="~/domains/nextlogicsolution.id/public_html/mis"

ROOT="$(cd "$(dirname "$0")" && pwd)"

scp -P "$SSH_PORT" \
  "$ROOT/app/Http/Controllers/Api/ImportExportController.php" \
  "$SSH_HOST:$REMOTE/app/Http/Controllers/Api/"

scp -P "$SSH_PORT" \
  "$ROOT/app/Http/Controllers/Api/ItemController.php" \
  "$SSH_HOST:$REMOTE/app/Http/Controllers/Api/"

scp -P "$SSH_PORT" \
  "$ROOT/app/Services/EntityImportService.php" \
  "$ROOT/app/Services/SpreadsheetService.php" \
  "$SSH_HOST:$REMOTE/app/Services/"

scp -P "$SSH_PORT" \
  "$ROOT/app/Models/Item.php" \
  "$SSH_HOST:$REMOTE/app/Models/"

echo "Uploaded. Clearing cache on server..."
ssh -p "$SSH_PORT" "$SSH_HOST" "cd $REMOTE && php artisan optimize:clear"

echo "Done. Test: Import Excel / Export on Items screen."

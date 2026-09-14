#!/usr/bin/env bash
# Fix missing CatalogInventorySyncService + documents controller on production.
set -euo pipefail

SSH="u683672103@145.79.58.104"
PORT=65002
REMOTE="domains/powerforceindustries.com/public_html"
ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "==> Upload missing services + controllers..."
scp -P "$PORT" \
  "$ROOT/app/Services/CatalogInventorySyncService.php" \
  "$ROOT/app/Services/InventoryCostService.php" \
  "$SSH:~/$REMOTE/app/Services/"

scp -P "$PORT" \
  "$ROOT/app/Http/Controllers/Api/DashboardController.php" \
  "$ROOT/app/Http/Controllers/Api/InventoryController.php" \
  "$ROOT/app/Http/Controllers/Api/ItemController.php" \
  "$ROOT/app/Http/Controllers/Api/DocumentController.php" \
  "$SSH:~/$REMOTE/app/Http/Controllers/Api/"

scp -P "$PORT" \
  "$ROOT/database/migrations/2026_07_07_160100_create_documents_table.php" \
  "$SSH:~/$REMOTE/database/migrations/2026_07_07_160100_create_documents_table.php"

echo "==> Migrate + clear cache..."
ssh -p "$PORT" "$SSH" << 'ENDSSH'
cd ~/domains/powerforceindustries.com/public_html
php artisan migrate --force
php artisan optimize:clear
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo class_exists('App\\Services\\CatalogInventorySyncService') ? 'CatalogInventorySyncService: OK' : 'CatalogInventorySyncService: MISSING'; echo PHP_EOL; echo class_exists('App\\Services\\InventoryCostService') ? 'InventoryCostService: OK' : 'InventoryCostService: MISSING'; echo PHP_EOL; echo Illuminate\\Support\\Facades\\Schema::hasTable('documents') ? 'documents table: OK' : 'documents table: MISSING'; echo PHP_EOL;"
ENDSSH

echo "Done. Hard refresh MIMS (Cmd+Shift+R)."

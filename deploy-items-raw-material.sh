#!/usr/bin/env bash
# Items in inventory: catalog sync + raw materials in items module.
set -euo pipefail

SSH="u683672103@145.79.58.104"
PORT=65002
REMOTE="domains/powerforceindustries.com/public_html"
ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "==> Upload ItemController, InventoryController, DashboardController, CatalogInventorySyncService..."
scp -P "$PORT" \
  "$ROOT/app/Http/Controllers/Api/ItemController.php" \
  "$ROOT/app/Http/Controllers/Api/InventoryController.php" \
  "$ROOT/app/Http/Controllers/Api/DashboardController.php" \
  "$SSH:~/$REMOTE/app/Http/Controllers/Api/"

scp -P "$PORT" \
  "$ROOT/app/Services/CatalogInventorySyncService.php" \
  "$ROOT/app/Services/EntityImportService.php" \
  "$ROOT/app/Services/InventoryCostService.php" \
  "$ROOT/app/Services/SpreadsheetService.php" \
  "$SSH:~/$REMOTE/app/Services/"

echo "==> Clear cache & prune legacy CAT-* placeholder rows..."
ssh -p "$PORT" "$SSH" << 'ENDSSH'
cd ~/domains/powerforceindustries.com/public_html
php artisan optimize:clear
php -r "require 'vendor/autoload.php'; \$app = require 'bootstrap/app.php'; \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo 'Pruned: '.app(App\Services\CatalogInventorySyncService::class)->pruneLegacyPlaceholders().PHP_EOL;"
echo "(API smoke test needs login token — Unauthenticated below is OK)"
curl -4 -s "https://powerforceindustries.com/api/inventory" -H "Accept: application/json" | head -c 300
echo ""
ENDSSH

echo "Done. Hard refresh app in browser (Cmd+Shift+R)."

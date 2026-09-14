#!/usr/bin/env bash
# Fix /api/documents 500 — upload DocumentController + ensure documents table exists.
set -euo pipefail

SSH_HOST="u683672103@145.79.58.104"
SSH_PORT="65002"
REMOTE="domains/powerforceindustries.com/public_html"
ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "==> Uploading DocumentController..."
scp -P "$SSH_PORT" \
  "$ROOT/app/Http/Controllers/Api/DocumentController.php" \
  "$SSH_HOST:~/$REMOTE/app/Http/Controllers/Api/DocumentController.php"

echo "==> Uploading documents migration (if missing on server)..."
scp -P "$SSH_PORT" \
  "$ROOT/database/migrations/2026_07_07_160100_create_documents_table.php" \
  "$SSH_HOST:~/$REMOTE/database/migrations/2026_07_07_160100_create_documents_table.php"

echo "==> Migrate + clear cache on server..."
ssh -p "$SSH_PORT" "$SSH_HOST" << ENDSSH
set -e
cd ~/$REMOTE
php artisan migrate --force
php artisan optimize:clear
echo ""
echo "=== documents table ==="
php -r "require 'vendor/autoload.php'; \$app=require 'bootstrap/app.php'; \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); echo Illuminate\\Support\\Facades\\Schema::hasTable('documents') ? 'documents table: OK' : 'documents table: MISSING'; echo PHP_EOL;"
ENDSSH

echo "Done. Retry: GET /api/documents?module=item&entity_id=514"

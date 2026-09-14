#!/usr/bin/env bash
# Upload dashboard reports UI — run on Mac.
set -euo pipefail
SSH="u683672103@145.79.58.104"
PORT=65002
REMOTE="domains/powerforceindustries.com/public_html"
ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "==> Uploading dashboard files..."
scp -P "$PORT" \
  "$ROOT/app/Services/DashboardWebService.php" \
  "$SSH:~/$REMOTE/app/Services/"

scp -P "$PORT" \
  "$ROOT/app/Http/Controllers/Web/DashboardController.php" \
  "$SSH:~/$REMOTE/app/Http/Controllers/Web/"

scp -P "$PORT" \
  "$ROOT/resources/views/partials/head.blade.php" \
  "$SSH:~/$REMOTE/resources/views/partials/"

scp -P "$PORT" \
  "$ROOT/resources/views/layouts/app.blade.php" \
  "$SSH:~/$REMOTE/resources/views/layouts/"

scp -P "$PORT" \
  "$ROOT/resources/views/dashboard/index.blade.php" \
  "$SSH:~/$REMOTE/resources/views/dashboard/"

scp -P "$PORT" \
  "$ROOT/public/css/mims.css" \
  "$SSH:~/$REMOTE/public/css/mims.css"

echo "==> Clear cache + copy CSS..."
ssh -p "$PORT" "$SSH" << 'ENDSSH'
cd ~/domains/powerforceindustries.com/public_html
php artisan view:clear
php artisan optimize:clear
mkdir -p ../public_html/mims/css
cp public/css/mims.css ../public_html/mims/css/mims.css
ENDSSH

echo "Done. Test: https://mims.powerforceindustries.com/dashboard"

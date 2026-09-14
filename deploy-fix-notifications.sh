#!/usr/bin/env bash
# Fix NotificationController on server — run on Mac.
set -euo pipefail
SSH="u683672103@145.79.58.104"
PORT=65002
REMOTE="domains/powerforceindustries.com/public_html"
ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "==> Uploading routes + NotificationController..."
scp -P "$PORT" "$ROOT/routes/web.php" "$SSH:~/$REMOTE/routes/web.php"
scp -P "$PORT" "$ROOT/routes/api.php" "$SSH:~/$REMOTE/routes/api.php"
scp -P "$PORT" "$ROOT/app/Http/Controllers/Api/NotificationController.php" \
  "$SSH:~/$REMOTE/app/Http/Controllers/Api/NotificationController.php"

echo "==> Clear cache + remove wrongly placed route files at app root..."
ssh -p "$PORT" "$SSH" << 'ENDSSH'
cd ~/domains/powerforceindustries.com/public_html
rm -f web.php api.php mims_web.php
php artisan route:clear
php artisan optimize:clear
echo "Routes (workflow):"
php artisan route:list --path=workflow 2>&1 | grep -E 'workflow|Notification' || true
ENDSSH

echo "Done."

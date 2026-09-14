#!/usr/bin/env bash
# Upload workflow fix only — run on Mac.
set -euo pipefail

SSH_HOST="u683672103@145.79.58.104"
SSH_PORT="65002"
REMOTE="domains/powerforceindustries.com/public_html"
ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "==> Uploading workflow controller, views, routes..."
scp -P "$SSH_PORT" \
  "$ROOT/app/Http/Controllers/Web/WorkflowController.php" \
  "$SSH_HOST:~/$REMOTE/app/Http/Controllers/Web/"

scp -P "$SSH_PORT" \
  "$ROOT/routes/web.php" \
  "$SSH_HOST:~/$REMOTE/routes/web.php"

scp -P "$SSH_PORT" \
  "$ROOT/routes/api.php" \
  "$SSH_HOST:~/$REMOTE/routes/api.php"

scp -P "$SSH_PORT" \
  "$ROOT/config/mims_web.php" \
  "$SSH_HOST:~/$REMOTE/config/mims_web.php"

scp -P "$SSH_PORT" \
  "$ROOT/app/Providers/AppServiceProvider.php" \
  "$SSH_HOST:~/$REMOTE/app/Providers/AppServiceProvider.php"

scp -P "$SSH_PORT" \
  "$ROOT/app/Http/Controllers/Api/NotificationController.php" \
  "$SSH_HOST:~/$REMOTE/app/Http/Controllers/Api/"

ssh -p "$SSH_PORT" "$SSH_HOST" "mkdir -p ~/$REMOTE/resources/views/workflow"

rsync -avz -e "ssh -p $SSH_PORT" \
  "$ROOT/resources/views/workflow/" \
  "$SSH_HOST:~/$REMOTE/resources/views/workflow/"

scp -P "$SSH_PORT" \
  "$ROOT/public/css/mims.css" \
  "$SSH_HOST:~/$REMOTE/public/css/mims.css"

echo "==> Server cache clear + CSS copy to mims..."
ssh -p "$SSH_PORT" "$SSH_HOST" << 'ENDSSH'
cd ~/domains/powerforceindustries.com/public_html
php artisan route:clear
php artisan view:clear
php artisan config:clear
php artisan optimize:clear
mkdir -p ../public_html/mims/css
cp public/css/mims.css ../public_html/mims/css/mims.css
echo "Routes:"
php artisan route:list --path=workflow 2>/dev/null | grep workflow || true
ENDSSH

echo "Done. Test: https://mims.powerforceindustries.com/workflow"

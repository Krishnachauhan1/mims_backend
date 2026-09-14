#!/usr/bin/env bash
# Fix CORS for Flutter web on mims → API on powerforceindustries.com/api
set -euo pipefail
SSH="u683672103@145.79.58.104"
PORT=65002
ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "==> Upload CORS middleware + bootstrap..."
scp -P "$PORT" \
  "$ROOT/app/Http/Middleware/HandlePreflightCors.php" \
  "$SSH:~/domains/powerforceindustries.com/public_html/app/Http/Middleware/"

scp -P "$PORT" \
  "$ROOT/bootstrap/app.php" \
  "$SSH:~/domains/powerforceindustries.com/public_html/bootstrap/"

echo "==> Upload public_html .htaccess (API routing + CORS headers)..."
scp -P "$PORT" \
  "$ROOT/deploy/hostinger/public_html.htaccess" \
  "$SSH:~/domains/powerforceindustries.com/public_html/.htaccess"

echo "==> Verify API + CORS preflight..."
ssh -p "$PORT" "$SSH" << 'ENDSSH'
curl -4 -s https://powerforceindustries.com/api/health | head -c 120
echo ""
curl -4 -s -o /dev/null -w "OPTIONS login: HTTP %{http_code}\n" \
  -X OPTIONS \
  -H "Origin: https://mims.powerforceindustries.com" \
  -H "Access-Control-Request-Method: POST" \
  -H "Access-Control-Request-Headers: content-type" \
  https://powerforceindustries.com/api/auth/login
curl -4 -s -o /dev/null -w "OPTIONS PUT route: HTTP %{http_code}\n" \
  -X OPTIONS \
  -H "Origin: https://mims.powerforceindustries.com" \
  -H "Access-Control-Request-Method: PUT" \
  -H "Access-Control-Request-Headers: content-type,authorization" \
  https://powerforceindustries.com/api/work-orders/41/route
cd ~/domains/powerforceindustries.com/public_html
php artisan optimize:clear 2>/dev/null || true
ENDSSH

echo "Done. Rebuild & redeploy Flutter if needed, then hard refresh mims site."

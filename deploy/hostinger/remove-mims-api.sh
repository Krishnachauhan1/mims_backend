#!/usr/bin/env bash
# Run ON THE SERVER (SSH) after uploading bootstrap + latest backend to public_html.
set -euo pipefail

DOMAIN="$HOME/domains/powerforceindustries.com"
WEB="$DOMAIN/public_html"
API_OLD="$DOMAIN/mims-api"

echo "==> Point API bootstrap to public_html only..."
cp "$WEB/laravel-api-index.php" "$WEB/laravel-api-index.php.bak.$(date +%s)" 2>/dev/null || true
cp "$WEB/mims/laravel-api-index.php" "$WEB/mims/laravel-api-index.php.bak.$(date +%s)" 2>/dev/null || true

cat > "$WEB/laravel-api-index.php" << 'EOF'
<?php

define('LARAVEL_START', microtime(true));

require __DIR__.'/laravel-bootstrap.php';

mims_bootstrap_laravel([
    __DIR__,
], jsonErrors: true);
EOF

cat > "$WEB/mims/laravel-api-index.php" << 'EOF'
<?php

define('LARAVEL_START', microtime(true));

require __DIR__.'/../laravel-bootstrap.php';

mims_bootstrap_laravel([
    __DIR__.'/..',
], true);
EOF

echo "==> Clear Laravel cache (public_html)..."
cd "$WEB"
php artisan route:clear
php artisan config:clear

echo "==> Health check..."
HTTP=$(curl -s -o /dev/null -w "%{http_code}" "https://powerforceindustries.com/api/health" || echo "000")
echo "    /api/health => HTTP $HTTP"

if [ ! -d "$API_OLD" ]; then
  echo "==> mims-api already removed."
  exit 0
fi

echo "==> Removing $API_OLD ..."
rm -rf "$API_OLD"

echo "==> Done. mims-api deleted; API runs from public_html."
echo "    Verify: grep dashboard/bootstrap $WEB/routes/api.php"
echo "    Verify: php artisan route:list --path=dashboard/bootstrap"

#!/usr/bin/env bash
# Run ON SERVER: bash server-finish.sh
# Fixes API 404 on Hostinger when rewrite to public/index.php fails.

set -euo pipefail

cd ~/domains/powerforceindustries.com/public_html

echo "==> Laravel API bootstrap (root index for /api/*)"
if [ -f deploy/hostinger/laravel-api-index.php ]; then
  cp deploy/hostinger/laravel-api-index.php laravel-api-index.php
else
  cat > laravel-api-index.php << 'PHP'
<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

if (file_exists($maintenance = __DIR__.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once __DIR__.'/bootstrap/app.php';

$app->handleRequest(Request::capture());
PHP
fi

echo "==> .htaccess"
cat > .htaccess << 'HTACCESS'
DirectoryIndex index.html index.php

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteBase /

    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    RewriteCond %{REQUEST_URI} ^/(api|up)(/|$) [NC]
    RewriteRule ^ laravel-api-index.php [L]
</IfModule>
HTACCESS

echo "==> Storage symlink"
mkdir -p storage/app/public
ln -sfn "$(pwd)/storage/app/public" "$(pwd)/public/storage"

echo "==> Website (minimal) if missing"
mkdir -p assets
if [ ! -f index.html ]; then
  cp deploy/hostinger/website/index.html index.html 2>/dev/null || cat > index.html << 'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Power Force Industries</title>
  <style>
    body{font-family:system-ui,sans-serif;margin:0;background:#fffdf7;color:#2b2450}
    .wrap{max-width:720px;margin:10vh auto;padding:2rem;text-align:center}
    h1{font-size:2rem} p{color:#7a73a3;line-height:1.6}
    a{color:#6c5ce7;font-weight:700}
  </style>
</head>
<body>
  <div class="wrap">
    <h1>Power Force Industries</h1>
    <p>Precision manufacturing &amp; inventory management.</p>
    <p><a href="mailto:info@powerforceindustries.com">Contact us</a></p>
  </div>
</body>
</html>
HTML
fi

chmod -R 775 storage bootstrap/cache 2>/dev/null || true
php artisan optimize:clear 2>/dev/null || true

echo ""
echo "==> Local PHP test"
php -r "echo 'PHP OK: '.PHP_VERSION.PHP_EOL;"
php laravel-api-index.php 2>&1 | head -3 || true

echo ""
echo "==> HTTP test"
curl -4 -sS -H "Accept: application/json" "https://powerforceindustries.com/api/health" || true
echo ""

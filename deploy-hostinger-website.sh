#!/usr/bin/env bash
# Upload landing website to powerforceindustries.com public_html
set -euo pipefail

SSH_HOST="u683672103@145.79.58.104"
SSH_PORT="65002"
REMOTE="domains/powerforceindustries.com/public_html"
ROOT="$(cd "$(dirname "$0")" && pwd)"

echo "==> Uploading website..."
scp -P "$SSH_PORT" -r \
  "$ROOT/deploy/hostinger/website/index.html" \
  "$SSH_HOST:~/$REMOTE/"

ssh -p "$SSH_PORT" "$SSH_HOST" "mkdir -p ~/$REMOTE/assets"
scp -P "$SSH_PORT" \
  "$ROOT/deploy/hostinger/website/assets/style.css" \
  "$SSH_HOST:~/$REMOTE/assets/"

echo "==> Uploading .htaccess + Laravel API bootstrap..."
scp -P "$SSH_PORT" \
  "$ROOT/deploy/hostinger/public_html.htaccess" \
  "$SSH_HOST:~/$REMOTE/.htaccess"
scp -P "$SSH_PORT" \
  "$ROOT/deploy/hostinger/laravel-api-index.php" \
  "$SSH_HOST:~/$REMOTE/laravel-api-index.php"

echo "Done. Open https://powerforceindustries.com"

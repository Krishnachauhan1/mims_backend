#!/usr/bin/env bash
# Import a MySQL dump into local DB from .env settings.
# Usage:
#   ./scripts/import-live-database.sh ~/Downloads/powerforce.sql
#   ./scripts/import-live-database.sh   # reads DUMP_FILE env or prompts
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

if [[ ! -f .env ]]; then
  echo "Missing .env — run ./scripts/setup-local.sh first"
  exit 1
fi

DUMP_FILE="${1:-${DUMP_FILE:-}}"
if [[ -z "$DUMP_FILE" ]]; then
  read -r -p "Path to .sql dump file: " DUMP_FILE
fi
if [[ ! -f "$DUMP_FILE" ]]; then
  echo "File not found: $DUMP_FILE"
  exit 1
fi

load_db_env() {
  local key line val
  for key in DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD; do
    line="$(grep -E "^${key}=" .env 2>/dev/null | tail -1 | sed 's/\r$//')" || true
    if [[ -z "$line" ]]; then
      continue
    fi
    val="${line#*=}"
    val="${val#"${val%%[![:space:]]*}"}"
    val="${val%"${val##*[![:space:]]}"}"
    if [[ "$val" =~ ^\".*\"$ ]]; then
      val="${val:1:${#val}-2}"
    elif [[ "$val" =~ ^\'.*\'$ ]]; then
      val="${val:1:${#val}-2}"
    fi
    printf -v "$key" '%s' "$val"
    export "$key"
  done
}

load_db_env

DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-mims_local}"
DB_USERNAME="${DB_USERNAME:-root}"
DB_PASSWORD="${DB_PASSWORD:-}"

if [[ -z "$DB_USERNAME" ]]; then
  echo "DB_USERNAME missing in .env — set DB_USERNAME=root (or your local MySQL user)" >&2
  exit 1
fi

echo "==> Create database if needed: $DB_DATABASE"
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
  -e "CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "==> Importing (this may take a few minutes)..."
mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" ${DB_PASSWORD:+-p"$DB_PASSWORD"} \
  "$DB_DATABASE" < "$DUMP_FILE"

echo "==> Laravel optimize"
php artisan config:clear
php artisan cache:clear

echo "Import complete → $DB_DATABASE"

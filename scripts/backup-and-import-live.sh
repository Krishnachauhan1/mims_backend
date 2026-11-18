#!/usr/bin/env bash
# Fresh live DB → ~/Downloads → import into mims_local (from .env).
# Usage: ./scripts/backup-and-import-live.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

"$ROOT/scripts/backup-live-database.sh"

LATEST="$(ls -t "${HOME}/Downloads"/powerforce-live-*.sql.gz 2>/dev/null | head -1)"
if [[ -z "$LATEST" ]]; then
  echo "No backup file found in ~/Downloads" >&2
  exit 1
fi

echo "==> Using latest backup: $LATEST"
gunzip -kf "$LATEST"
SQL_PATH="${LATEST%.gz}"
"$ROOT/scripts/import-live-database.sh" "$SQL_PATH"

echo ""
echo "Local DB ready. Start API: php artisan serve --host=127.0.0.1 --port=8000"
#!/usr/bin/env bash
# Export live MySQL from Hostinger, download to ~/Downloads.
# Usage: ./scripts/backup-live-database.sh
# SSH password once per run (multiplexed) unless you use SSH keys.
set -euo pipefail

SSH_HOST="u683672103@145.79.58.104"
SSH_PORT="65002"
SSH_MUX_SOCK="${HOME}/.ssh/mims-live-%C"
mkdir -p "${HOME}/.ssh"
cleanup_mux() {
  ssh -p "$SSH_PORT" -o "ControlPath=${SSH_MUX_SOCK}" -O exit "$SSH_HOST" 2>/dev/null || true
}
trap cleanup_mux EXIT
SSH_OPTS=(-p "$SSH_PORT" -o ControlMaster=auto -o "ControlPath=${SSH_MUX_SOCK}" -o ControlPersist=120)
SCP_OPTS=(-P "$SSH_PORT" -o "ControlPath=${SSH_MUX_SOCK}")
REMOTE_APP="domains/powerforceindustries.com/public_html"
STAMP="$(date +%Y%m%d-%H%M%S)"
REMOTE_NAME="powerforce-live-${STAMP}.sql.gz"
REMOTE_PATH="~/${REMOTE_NAME}"
LOCAL_DIR="${HOME}/Downloads"
LOCAL_PATH="${LOCAL_DIR}/${REMOTE_NAME}"

mkdir -p "$LOCAL_DIR"

echo "==> Dump live database on server → ~/${REMOTE_NAME}"
echo "    (SSH password once if prompted)"
ssh "${SSH_OPTS[@]}" "$SSH_HOST" bash -s "$REMOTE_APP" "$REMOTE_NAME" <<'ENDSSH'
set -euo pipefail
REMOTE_APP="$1"
REMOTE_NAME="$2"
cd ~/"$REMOTE_APP"
if [[ ! -f .env ]] || [[ ! -f vendor/autoload.php ]]; then
  echo "Missing Laravel app in $REMOTE_APP" >&2
  exit 1
fi
APP_ROOT="$(pwd)"
DUMP="$HOME/$REMOTE_NAME"
CFG_FILE="$(mktemp)"
php <<'PHPEOF' > "$CFG_FILE"
<?php
$root = getcwd();
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$cfg = config('database.connections.mysql');
$host = (string) ($cfg['host'] ?? '127.0.0.1');
$port = (int) ($cfg['port'] ?? 3306);
$user = (string) ($cfg['username'] ?? '');
$pass = (string) ($cfg['password'] ?? '');
$db = (string) ($cfg['database'] ?? '');
if ($db === '' || $user === '') {
    fwrite(STDERR, "DB credentials missing in config\n");
    exit(1);
}
fwrite(STDERR, "Database: {$db} @ {$host}:{$port}\n");
echo 'export DB_HOST=' . escapeshellarg($host) . PHP_EOL;
echo 'export DB_PORT=' . escapeshellarg((string) $port) . PHP_EOL;
echo 'export DB_DATABASE=' . escapeshellarg($db) . PHP_EOL;
echo 'export DB_USERNAME=' . escapeshellarg($user) . PHP_EOL;
echo 'export DB_PASSWORD=' . escapeshellarg($pass) . PHP_EOL;
PHPEOF
# shellcheck disable=SC1090
set -a
source "$CFG_FILE"
set +a
rm -f "$CFG_FILE"
mysqldump -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USERNAME" -p"$DB_PASSWORD" \
  --single-transaction --quick --routines --triggers "$DB_DATABASE" \
  | gzip -9 > "$DUMP"
ls -lh "$DUMP"
ENDSSH

echo "==> Download to ${LOCAL_PATH}"
scp "${SCP_OPTS[@]}" "${SSH_HOST}:${REMOTE_NAME}" "$LOCAL_PATH"

echo "==> Remove remote dump (optional cleanup)"
ssh "${SSH_OPTS[@]}" "$SSH_HOST" "rm -f ~/${REMOTE_NAME}" || true

echo ""
echo "Done. Backup saved:"
echo "  ${LOCAL_PATH}"
echo ""
echo "Import locally:"
echo "  cd $(cd "$(dirname "$0")/.." && pwd)"
echo "  ./scripts/import-live-database.sh \"${LOCAL_PATH%.gz}\""
echo "  # or gunzip first: gunzip -k \"${LOCAL_PATH}\""

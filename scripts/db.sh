#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
BACKUP_DIR="$PROJECT_ROOT/backups/mysql"

usage() {
  cat <<'EOF'
Usage:
  scripts/db.sh backup
  scripts/db.sh restore <path-to-sql-file>

Backs up / restores the MySQL database running in the `db` container.
Media files (storage/app/public/albums) are NOT included - copy them separately.
EOF
}

if [[ $# -lt 1 ]]; then
  usage
  exit 1
fi

ACTION="$1"
mkdir -p "$BACKUP_DIR"
cd "$PROJECT_ROOT"

case "$ACTION" in
  backup)
    TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
    OUT_FILE="$BACKUP_DIR/backup_${TIMESTAMP}.sql"
    docker compose exec -T db sh -c 'exec mysqldump -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' > "$OUT_FILE"
    echo "Backup saved: $OUT_FILE"
    ;;
  restore)
    if [[ $# -ne 2 ]]; then
      usage
      exit 1
    fi
    IN_FILE="$2"
    if [[ ! -f "$IN_FILE" ]]; then
      echo "File not found: $IN_FILE" >&2
      exit 1
    fi
    docker compose exec -T db sh -c 'exec mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"' < "$IN_FILE"
    echo "Restore completed from: $IN_FILE"
    ;;
  *)
    usage
    exit 1
    ;;
esac

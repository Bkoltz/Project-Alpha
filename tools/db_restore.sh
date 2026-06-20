#!/usr/bin/env bash
# tools/db_restore.sh — restore a Project Alpha database backup.
#
# Usage:
#   ./tools/db_restore.sh backups/project_alpha_20260611_120000.sql.gz
#   ./tools/db_restore.sh dump.sql            # plain .sql also accepted
#
# SAFETY:
#   - Takes a pre-restore safety dump automatically (backups/pre_restore_*.sql.gz)
#   - Asks for confirmation (set FORCE=1 to skip, e.g. in scripts)
#
# Reads credentials from .env. Execs mysql INSIDE the db container.

set -euo pipefail

REPO_DIR="${REPO_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$REPO_DIR"

if [[ $# -lt 1 ]]; then
  echo "Usage: $0 <backup.sql[.gz]>" >&2
  exit 1
fi
IN="$1"
if [[ ! -f "$IN" ]]; then
  echo "ERROR: file not found: $IN" >&2
  exit 1
fi

# shellcheck disable=SC1091
set -a; source .env; set +a
DB_NAME="${MYSQL_DATABASE:-project_alpha}"

echo "About to RESTORE '${DB_NAME}' from: $IN"
echo "This OVERWRITES current data in ${DB_NAME}."
if [[ "${FORCE:-0}" != "1" ]]; then
  read -r -p "Type 'yes' to continue: " ans
  [[ "$ans" == "yes" ]] || { echo "Aborted."; exit 1; }
fi

# Pre-restore safety dump
STAMP="$(date +%Y%m%d_%H%M%S)"
SAFETY="backups/pre_restore_${DB_NAME}_${STAMP}.sql.gz"
mkdir -p backups
echo "Taking pre-restore safety dump -> ${SAFETY}"
docker compose exec -T db mysqldump \
  -uroot -p"${MYSQL_ROOT_PASSWORD}" \
  --single-transaction --routines --triggers --events --no-tablespaces \
  "${DB_NAME}" | gzip > "$SAFETY"

echo "Restoring..."
if [[ "$IN" == *.gpg ]]; then
  # Encrypted backup — decrypt first
  if [[ -z "${BACKUP_ENCRYPTION_KEY:-}" ]]; then
    echo "ERROR: BACKUP_ENCRYPTION_KEY not set in .env — cannot decrypt $IN" >&2
    exit 1
  fi
  gpg --batch --passphrase "${BACKUP_ENCRYPTION_KEY}" --decrypt "$IN" 2>/dev/null | \
    { [[ "$IN" == *.gz.gpg ]] && gzip -cd || cat; } | \
    docker compose exec -T db mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${DB_NAME}"
elif [[ "$IN" == *.gz ]]; then
  gzip -cd "$IN" | docker compose exec -T db mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${DB_NAME}"
else
  docker compose exec -T db mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" "${DB_NAME}" < "$IN"
fi

# Quick verification
TABLES=$(docker compose exec -T db mysql -uroot -p"${MYSQL_ROOT_PASSWORD}" -N -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='${DB_NAME}'")
echo "OK: restore complete — ${DB_NAME} now has ${TABLES} tables."
echo "Safety dump retained at: ${SAFETY}"

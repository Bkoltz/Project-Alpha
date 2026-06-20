#!/usr/bin/env bash
# tools/db_backup.sh — dump the Project Alpha database to a gzipped SQL file.
#
# Usage:
#   ./tools/db_backup.sh                  # writes backups/project_alpha_YYYYmmdd_HHMMSS.sql.gz[.gpg]
#   ./tools/db_backup.sh /path/out.sql.gz # explicit output path
#
# Reads credentials from .env (same file docker compose uses).
# Execs mysqldump INSIDE the db container, so it works with the
# no-host-port hardened compose setup.
#
# Encryption: if BACKUP_ENCRYPTION_KEY is set in .env, the gzip output is
# additionally encrypted with AES-256 via gpg --symmetric. The .sql.gz file
# is replaced by a .sql.gz.gpg file. Set BACKUP_ENCRYPTION_KEY to a strong
# passphrase (e.g. openssl rand -hex 32).
#
# Retention: keeps the most recent $KEEP backups (default 14) in backups/.

set -euo pipefail

REPO_DIR="${REPO_DIR:-$(cd "$(dirname "$0")/.." && pwd)}"
cd "$REPO_DIR"

if [[ ! -f .env ]]; then
  echo "ERROR: .env not found in $REPO_DIR" >&2
  exit 1
fi

# shellcheck disable=SC1091
set -a; source .env; set +a

DB_NAME="${MYSQL_DATABASE:-project_alpha}"
KEEP="${KEEP:-14}"

STAMP="$(date +%Y%m%d_%H%M%S)"
OUT="${1:-backups/${DB_NAME}_${STAMP}.sql.gz}"
mkdir -p "$(dirname "$OUT")"

echo "Dumping ${DB_NAME} -> ${OUT}"
# --single-transaction: consistent InnoDB snapshot without locking
# --routines --triggers --events: include all programmable objects
docker compose exec -T db mysqldump \
  -uroot -p"${MYSQL_ROOT_PASSWORD}" \
  --single-transaction --routines --triggers --events \
  --no-tablespaces \
  "${DB_NAME}" | gzip > "$OUT"

# sanity check: a real dump ends with "Dump completed"
if ! gzip -cd "$OUT" | tail -1 | grep -q "Dump completed"; then
  echo "ERROR: dump did not complete cleanly — keeping file for inspection: $OUT" >&2
  exit 1
fi

# Encrypt the backup if a passphrase is configured
ENCRYPTED=""
if [[ -n "${BACKUP_ENCRYPTION_KEY:-}" ]]; then
  echo "Encrypting with AES-256 (gpg)..."
  gpg --batch --yes --passphrase "${BACKUP_ENCRYPTION_KEY}" \
    --symmetric --cipher-algo AES256 "$OUT"
  rm -f "$OUT"
  OUT="${OUT}.gpg"
  ENCRYPTED=".gpg"
  # Verify the encrypted file is valid GPG
  if ! gpg --batch --passphrase "${BACKUP_ENCRYPTION_KEY}" --decrypt "$OUT" >/dev/null 2>&1; then
    echo "ERROR: encrypted backup verification failed — keeping file for inspection: $OUT" >&2
    exit 1
  fi
fi

SIZE="$(du -h "$OUT" | cut -f1)"
echo "OK: ${OUT} (${SIZE})${ENCRYPTED:+ [encrypted]}"

# Retention: prune old backups beyond $KEEP (only in the default backups/ dir)
if [[ "$OUT" == backups/* ]]; then
  ls -1t backups/${DB_NAME}_*.sql.gz* 2>/dev/null | tail -n +$((KEEP + 1)) | while read -r old; do
    echo "Pruning old backup: $old"
    rm -f "$old"
  done
fi

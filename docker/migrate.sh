#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
DB_NAME="${MYSQL_DATABASE:-project_alpha}"
ROOT_USER="${MYSQL_ROOT_USER:-root}"
ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-}"
BASELINE="/usr/local/share/app-migrations/baseline.sql"

if [[ ! "$DB_NAME" =~ ^[A-Za-z0-9_]+$ ]]; then
  echo "Migration failed: MYSQL_DATABASE contains unsupported characters." >&2
  exit 1
fi
if [ -z "$ROOT_PASSWORD" ]; then
  echo "Migration failed: MYSQL_ROOT_PASSWORD is required by the one-shot migrator." >&2
  exit 1
fi

echo "Waiting for the database migration connection..."
for attempt in $(seq 1 60); do
  if mysqladmin --skip-ssl ping -h "$DB_HOST" -P "$DB_PORT" -u"$ROOT_USER" --password="$ROOT_PASSWORD" --silent >/dev/null 2>&1; then
    break
  fi
  if [ "$attempt" -eq 60 ]; then
    echo "Migration failed: database did not become ready." >&2
    exit 1
  fi
  sleep 2
done

table_count=$(mysql --skip-ssl -h "$DB_HOST" -P "$DB_PORT" -u"$ROOT_USER" --password="$ROOT_PASSWORD" -N -s -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME'")
ledger_count=$(mysql --skip-ssl -h "$DB_HOST" -P "$DB_PORT" -u"$ROOT_USER" --password="$ROOT_PASSWORD" -N -s -e \
  "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_NAME' AND table_name = 'schema_migrations'")

if [ "$ledger_count" -eq 0 ]; then
  if [ "$table_count" -ne 0 ]; then
    echo "Migration failed: non-empty pre-0.5.0 database detected." >&2
    echo "This release requires the documented destructive reinstall; no legacy schema will be modified." >&2
    exit 1
  fi
  if [ ! -f "$BASELINE" ]; then
    echo "Migration failed: baseline.sql is missing from the image." >&2
    exit 1
  fi
  echo "Applying Project Alpha 0.5.0 database baseline..."
  mysql --skip-ssl -h "$DB_HOST" -P "$DB_PORT" -u"$ROOT_USER" --password="$ROOT_PASSWORD" --database="$DB_NAME" < "$BASELINE"
fi

php /var/www/src/migrations/run_migrations.php --verbose
/usr/local/bin/enable-mysql-encryption.sh
echo "Database initialization and validation completed successfully. Create the first administrator in the web setup if this is a clean installation."

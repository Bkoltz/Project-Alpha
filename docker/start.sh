#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
ROOT_USER="${MYSQL_ROOT_USER:-root}"
ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-rootpass}"

echo "Waiting for DB at ${DB_HOST}:${DB_PORT} (user=${ROOT_USER})..."

retries=60
wait_interval=2

counter=0
while [ $counter -lt $retries ]; do
  if command -v mysqladmin > /dev/null 2>&1; then
    # Disable SSL because the client is MariaDB and the server presents a self-signed cert by default
    mysqladmin --skip-ssl ping -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" --silent > /dev/null 2>&1 && {
      echo "✅ DB is ready (mysqladmin responded as root)."
      break
    }
  else
    (echo > /dev/tcp/${DB_HOST}/${DB_PORT}) > /dev/null 2>&1 && {
      echo "✅ DB TCP port is open."
      break
    }
  fi

  counter=$((counter+1))
  echo "⏳ Still waiting for DB... (${counter}/${retries})"
  sleep ${wait_interval}
done

if [ $counter -ge $retries ]; then
  echo "❌ DB did not become available after $((retries*wait_interval)) seconds. Last checked host=${DB_HOST} port=${DB_PORT}"
  exit 1
fi

# Apply base schema if missing, then runtime migrations (both idempotent)
DB_NAME="${MYSQL_DATABASE:-project_alpha}"

# 1) Base schema: if key table (quotes) is missing, load 000_all.sql
if [ -f "/usr/local/share/app-migrations/000_all.sql" ]; then
  echo "Checking if base schema needs to be applied to '${DB_NAME}'..."
  if ! mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -N -e \
       "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='quotes' LIMIT 1" | grep -q 1; then
    echo "Applying base schema (000_all.sql) to '${DB_NAME}'..."
    if mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -D "${DB_NAME}" < \
         "/usr/local/share/app-migrations/000_all.sql" > /dev/null 2>&1; then
      echo "✅ Base schema applied."
    else
      echo "⚠️  Failed to apply base schema (000_all.sql). The application may not work until this succeeds."
    fi
  else
    echo "Base schema already present (quotes table exists)."
  fi
else
  echo "ℹ️  No base schema file (000_all.sql) found in image; skipping."
fi

# 2) Runtime, always safe to re-run
if [ -f "/usr/local/share/app-migrations/runtime.sql" ]; then
  echo "Applying runtime migrations to database '${DB_NAME}' (if needed)..."
  if mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -D "${DB_NAME}" < \
       "/usr/local/share/app-migrations/runtime.sql" > /dev/null 2>&1; then
    echo "✅ Runtime migrations applied (or already up-to-date)."
  else
    echo "⚠️  Runtime migrations encountered errors (continuing). Check logs if issues persist."
  fi
else
  echo "ℹ️  No runtime migration file present. Skipping."
fi

echo "🚀 Starting Apache..."
exec apache2-foreground

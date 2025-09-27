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

echo "🚀 Starting Apache..."
exec apache2-foreground

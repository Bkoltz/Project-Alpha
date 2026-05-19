#!/usr/bin/env bash
set -euo pipefail

# ── Export environment variables so cron jobs can access them ──
# Cron does NOT inherit the container's env vars, so we dump them
# to /etc/environment which each cron job sources before running.
printenv | grep -E '^(MYSQL_|DB_|APP_|STRIPE_|SMTP_)' | sed 's/=\(.*\)/="\1"/' > /etc/environment

# ── Wait for the database to become available ──
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
RETRIES=60
WAIT=2

echo "[cron-entrypoint] Waiting for DB at ${DB_HOST}:${DB_PORT}..."

counter=0
while [ $counter -lt $RETRIES ]; do
  if command -v mysqladmin > /dev/null 2>&1; then
    mysqladmin --skip-ssl ping \
      -h "${DB_HOST}" -P "${DB_PORT}" \
      -u"${MYSQL_USER:-root}" \
      --password="${MYSQL_PASSWORD:-${MYSQL_ROOT_PASSWORD:-rootpass}}" \
      --silent > /dev/null 2>&1 && {
      echo "[cron-entrypoint] DB is ready."
      break
    }
  else
    (echo > /dev/tcp/${DB_HOST}/${DB_PORT}) > /dev/null 2>&1 && {
      echo "[cron-entrypoint] DB TCP port is open."
      break
    }
  fi

  counter=$((counter + 1))
  echo "[cron-entrypoint] Still waiting for DB... (${counter}/${RETRIES})"
  sleep ${WAIT}
done

if [ $counter -ge $RETRIES ]; then
  echo "[cron-entrypoint] ERROR: DB did not become available after $((RETRIES * WAIT)) seconds."
  exit 1
fi

# ── Start cron in the foreground ──
echo "[cron-entrypoint] Starting cron..."
exec cron -f

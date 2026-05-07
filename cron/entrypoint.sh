#!/usr/bin/env bash
set -euo pipefail

# ── Export environment variables so cron jobs can access them ──
# Cron does NOT inherit the container's env vars, so we dump them
# to /etc/environment which each cron job sources before running.
printenv | grep -E '^(MYSQL_|DB_|APP_|STRIPE_|SMTP_)' | sed 's/=\(.*\)/="\1"/' > /etc/environment

# ── Create log directory if it doesn't exist ──
LOG_DIR="/var/www/logs"
if [ ! -d "$LOG_DIR" ]; then
    mkdir -p "$LOG_DIR"
    echo "[cron-entrypoint] Created log directory: $LOG_DIR"
fi

# Ensure log directory is writable
chmod 775 "$LOG_DIR"

# Create symlink for legacy cron log directory if needed
LEGACY_LOG_DIR="/var/log/cron"
if [ ! -d "$LEGACY_LOG_DIR" ]; then
    mkdir -p "$LEGACY_LOG_DIR"
fi

# Set up log redirection: cron job output goes to both legacy location and unified log
# This is handled in the crontab by redirecting output to the log file

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

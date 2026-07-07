#!/usr/bin/env bash
set -euo pipefail

# Auto-generate or load encryption key if not provided (same logic as web start.sh)
CONFIG_DIR="/var/www/config"
if [ -z "${APP_ENCRYPTION_KEY:-}" ]; then
  KEY_FILE="${CONFIG_DIR}/.encryption_key"
  if [ -f "$KEY_FILE" ]; then
    export APP_ENCRYPTION_KEY="$(cat "$KEY_FILE")"
  else
    # Cron container might start before web generates the key
    # Generate a temporary one — the web container's key will take precedence
    # since app_config stores the encrypted values, not the cron container
    export APP_ENCRYPTION_KEY="$(php -r 'echo base64_encode(random_bytes(32));')"
  fi
fi

# ── Export environment variables so cron jobs can access them ──
# Cron does NOT inherit the container's env vars, so we dump them
# to /etc/environment which each cron job sources before running.
printenv | grep -E '^(MYSQL_|DB_|APP_|STRIPE_|SMTP_|BACKUP_)' | sed 's/=\(.*\)/="\1"/' > /etc/environment

# ── Create log directory if it doesn't exist ──
LOG_DIR="/var/www/config/logs/cron"
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
APP_TIMEZONE="${APP_TIMEZONE:-}"
if command -v mysql > /dev/null 2>&1; then
  APP_TIMEZONE="$(mysql --skip-ssl \
    -h "${DB_HOST}" -P "${DB_PORT}" \
    -u"${MYSQL_USER:-root}" \
    --password="${MYSQL_PASSWORD:-${MYSQL_ROOT_PASSWORD:-rootpass}}" \
    -D "${MYSQL_DATABASE:-project_alpha}" \
    --batch --skip-column-names \
    -e "SELECT config_value FROM app_config WHERE organization_id=0 AND config_key='timezone' LIMIT 1" 2>/dev/null || true)"
fi

if [ -z "$APP_TIMEZONE" ]; then
  APP_TIMEZONE="${TZ:-UTC}"
fi

if [ -f "/usr/share/zoneinfo/${APP_TIMEZONE}" ]; then
  ln -snf "/usr/share/zoneinfo/${APP_TIMEZONE}" /etc/localtime
  echo "${APP_TIMEZONE}" > /etc/timezone
  export TZ="${APP_TIMEZONE}"
  echo "[cron-entrypoint] Using PA timezone: ${APP_TIMEZONE}"
else
  export TZ="UTC"
  echo "[cron-entrypoint] Timezone '${APP_TIMEZONE}' is unavailable; using UTC."
fi

grep -v '^TZ=' /etc/environment > /tmp/project-alpha-environment || true
echo "TZ=\"${TZ}\"" >> /tmp/project-alpha-environment
mv /tmp/project-alpha-environment /etc/environment

echo "[cron-entrypoint] Running startup Stripe reconciliation..."
php /var/www/src/cron/stripe_reconciliation.php --startup || echo "[cron-entrypoint] Startup Stripe reconciliation failed; cron will continue."

echo "[cron-entrypoint] Starting cron..."
exec cron -f

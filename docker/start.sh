done
#!/usr/bin/env bash
set -euo pipefail

# Configuration (fall back to sensible defaults)
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
# Prefer checking with the root account (created by the MySQL image). If not present,
# fall back to a no-auth ping using mysqladmin (if allowed) or TCP.
ROOT_USER="${MYSQL_ROOT_USER:-root}"
ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-}"

echo "Waiting for DB at ${DB_HOST}:${DB_PORT} (user=${MYSQL_USER})..."

# Prefer using mysqladmin (installed in the image via Dockerfile). If it's not available
# fall back to a simple TCP check. Increase retries to allow longer DB init (migrations etc.).
retries=60
wait_interval=2

#!/usr/bin/env bash
set -euo pipefail

# Configuration (fall back to sensible defaults)
DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
# Prefer checking with the root account (created by the MySQL image). If not present,
# fall back to a no-auth ping using mysqladmin (if allowed) or TCP.
ROOT_USER="${MYSQL_ROOT_USER:-root}"
ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-}"

echo "Waiting for DB at ${DB_HOST}:${DB_PORT} (user=${MYSQL_USER:-appuser})..."

# Prefer using mysqladmin (installed in the image via Dockerfile). If it's not available
# fall back to a simple TCP check. Increase retries to allow longer DB init (migrations etc.).
retries=60
wait_interval=2

wait_with_mysqladmin() {
  # use --silent to suppress output; mysqladmin returns 0 when server is ready
  if [ -n "${MYSQL_PASSWORD:-}" ]; then
    mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" -u"${MYSQL_USER:-}" --password="${MYSQL_PASSWORD:-}" --silent > /dev/null 2>&1
  else
    mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" -u"${MYSQL_USER:-}" --silent > /dev/null 2>&1
  fi
}

wait_with_tcp() {
  (echo > /dev/tcp/${DB_HOST}/${DB_PORT}) > /dev/null 2>&1
}

counter=0
while [ $counter -lt $retries ]; do
  if command -v mysqladmin > /dev/null 2>&1; then
    # Try authenticating as root if we have a root password. This avoids failing
    # when the app-specific user isn't created yet during DB init scripts.
    if [ -n "${ROOT_PASSWORD}" ]; then
      mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" --silent > /dev/null 2>&1 && {
        echo "DB is ready (mysqladmin responded as root)."
        break
      }
    else
      # No root password provided: try pinging without credentials
      mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" --silent > /dev/null 2>&1 && {
        echo "DB is ready (mysqladmin responded without auth)."
        break
      }
    fi
  else
    if wait_with_tcp; then
      echo "DB TCP port is open."
      break
    fi
  fi

  counter=$((counter+1))
  echo "  still waiting for DB... (${counter}/${retries})"
  sleep ${wait_interval}
done

if [ $counter -ge $retries ]; then
  echo "DB did not become available after $((retries*wait_interval)) seconds. Last checked host=${DB_HOST} port=${DB_PORT}"
  exit 1
fi

echo "Starting Apache..."
exec apache2-foreground
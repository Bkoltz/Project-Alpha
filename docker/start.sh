#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
ROOT_USER="${MYSQL_ROOT_USER:-root}"
ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-}"
APP_USER="${MYSQL_USER:-appuser}"
APP_PASSWORD="${MYSQL_PASSWORD:-}"

echo "Waiting for DB at ${DB_HOST}:${DB_PORT} (user=${APP_USER})..."

retries=60
wait_interval=2

wait_with_mysqladmin() {
  if [ -n "${APP_PASSWORD}" ]; then
    mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" -u"${APP_USER}" --password="${APP_PASSWORD}" --silent > /dev/null 2>&1
  else
    mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" -u"${APP_USER}" --silent > /dev/null 2>&1
  fi
}

wait_with_tcp() {
  (echo > /dev/tcp/${DB_HOST}/${DB_PORT}) > /dev/null 2>&1
}

counter=0
while [ $counter -lt $retries ]; do
  if command -v mysqladmin > /dev/null 2>&1; then
    if [ -n "${ROOT_PASSWORD}" ]; then
      mysqladmin ping -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" --silent > /dev/null 2>&1 && {
        echo "DB is ready (mysqladmin responded as root)."
        break
      }
    else
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
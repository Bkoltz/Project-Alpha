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

# Compute admin password hash
ADMIN_PASSWORD="${ADMIN_PASSWORD}"
if [ -z "$ADMIN_PASSWORD" ]; then
  echo "❌ ADMIN_PASSWORD environment variable is required"
  exit 1
fi
ADMIN_PASSWORD_HASH=$(php -r "echo password_hash('${ADMIN_PASSWORD}', PASSWORD_DEFAULT);")
echo "Using admin password hash: ${ADMIN_PASSWORD_HASH}"

# Replace placeholder in SQL files (use a non-/ delimiter to avoid issues with bcrypt hashes)
for sql_file in /usr/local/share/app-migrations/*.sql; do
  if [ -f "$sql_file" ]; then
    sed -i "s|{{ADMIN_PASSWORD_HASH}}|${ADMIN_PASSWORD_HASH}|g" "$sql_file"
  fi
done

# If the DB already exists and contains the placeholder hash (from a previous run), update it
mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -D "${DB_NAME}" -e \
  "UPDATE users SET password_hash='${ADMIN_PASSWORD_HASH}' WHERE password_hash='{{ADMIN_PASSWORD_HASH}}';" || true

# 1) Base schema: if key table (quotes) is missing, load migration files
if ! mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -N -e \
     "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='quotes' LIMIT 1" | grep -q 1; then
  echo "Applying base schema to '${DB_NAME}'..."
  
  # Run all migration files in order
  for sql_file in /usr/local/share/app-migrations/*.sql; do
    if [ -f "$sql_file" ]; then
      echo "Applying migration: $(basename "$sql_file")"
      if mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -D "${DB_NAME}" < "$sql_file" > /dev/null 2>&1; then
        echo "✅ Applied: $(basename "$sql_file")"
      else
        echo "⚠️ Failed to apply: $(basename "$sql_file")"
      fi
    fi
  done
  
  echo "✅ Base schema applied."
else
  echo "Base schema already present (quotes table exists)."
fi

# 2) Runtime, always safe to re-run
if [ -f "/usr/local/share/app-migrations/runtime.sql" ]; then
  echo "Applying runtime migrations to database '${DB_NAME}' (if needed)..."
  echo "Debug: Executing runtime.sql from $(ls -l /usr/local/share/app-migrations/runtime.sql)"
  
  # Execute with verbose error reporting
  set +e  # Temporarily disable exit on error
  mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -D "${DB_NAME}" -v < \
       "/usr/local/share/app-migrations/runtime.sql" 2>&1 | tee /tmp/migration.log
  MIGRATION_EXIT=${PIPESTATUS[0]}
  set -e  # Re-enable exit on error
  
  if [ $MIGRATION_EXIT -eq 0 ]; then
    echo "✅ Runtime migrations applied (or already up-to-date)."
  else
    echo "⚠️  Runtime migrations encountered errors (exit code $MIGRATION_EXIT):"
    cat /tmp/migration.log
    echo "Error details saved in /tmp/migration.log"
    echo "Continuing anyway, but the application may not work correctly."
  fi
else
  echo "ℹ️  No runtime migration file present. Skipping."
fi

# 3) Seed config directory with defaults if mounted and empty
CONFIG_DIR="/var/www/config"
if [ ! -d "${CONFIG_DIR}" ]; then
  mkdir -p "${CONFIG_DIR}" || true
fi
if [ -d "${CONFIG_DIR}" ]; then
  if [ ! -f "${CONFIG_DIR}/settings.json" ]; then
    echo "Seeding default settings.json into ${CONFIG_DIR}..."
    cat > "${CONFIG_DIR}/settings.json" <<'JSON'
{
  "brand_name": "Project Alpha",
  "logo_path": null,
  "from_name": null,
  "from_address_line1": null,
  "from_address_line2": null,
  "from_city": null,
  "from_state": null,
  "from_postal": null,
  "from_country": null,
  "from_email": null,
  "from_phone": null,
  "terms": null,
  "net_terms_days": 30,
  "payment_methods": ["card","cash","bank_transfer"]
}
JSON
    chown www-data:www-data "${CONFIG_DIR}/settings.json" || true
    chmod 664 "${CONFIG_DIR}/settings.json" || true
  fi
  if [ ! -d "${CONFIG_DIR}/uploads" ]; then
    mkdir -p "${CONFIG_DIR}/uploads" || true
    chown -R www-data:www-data "${CONFIG_DIR}/uploads" || true
    chmod 775 "${CONFIG_DIR}/uploads" || true
  fi
fi

# 5) Ensure uploads directories exist with correct permissions
UPLOADS_DIR="/var/www/src/uploads"
if [ ! -d "${UPLOADS_DIR}/receipts" ]; then
  echo "Creating receipts upload directory..."
  mkdir -p "${UPLOADS_DIR}/receipts" || true
fi
if [ ! -d "${UPLOADS_DIR}/forms" ]; then
  echo "Creating forms upload directory..."
  mkdir -p "${UPLOADS_DIR}/forms" || true
fi
chown -R www-data:www-data "${UPLOADS_DIR}" || true
chmod -R 775 "${UPLOADS_DIR}" || true

# 6) Database and config setup complete
# Note: Cron jobs are now handled by the separate 'cron' service in docker-compose.yml
# This web service no longer manages scheduled tasks.

echo "✅ Setup complete."
exec apache2-foreground

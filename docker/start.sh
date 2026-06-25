#!/usr/bin/env bash
set -euo pipefail

DB_HOST="${DB_HOST:-db}"
DB_PORT="${DB_PORT:-3306}"
ROOT_USER="${MYSQL_ROOT_USER:-root}"
ROOT_PASSWORD="${MYSQL_ROOT_PASSWORD:-rootpass}"

# Auto-generate encryption key if not provided (persists in config volume)
CONFIG_DIR="/var/www/config"
if [ -z "${APP_ENCRYPTION_KEY:-}" ]; then
  KEY_FILE="${CONFIG_DIR}/.encryption_key"
  if [ -f "$KEY_FILE" ]; then
    export APP_ENCRYPTION_KEY="$(cat "$KEY_FILE")"
    echo "Loaded encryption key from ${KEY_FILE}"
  else
    echo "APP_ENCRYPTION_KEY not set — auto-generating and persisting to ${KEY_FILE}"
    mkdir -p "$CONFIG_DIR"
    export APP_ENCRYPTION_KEY="$(php -r 'echo base64_encode(random_bytes(32));')"
    echo "$APP_ENCRYPTION_KEY" > "$KEY_FILE"
    chmod 600 "$KEY_FILE"
    chown www-data:www-data "$KEY_FILE" 2>/dev/null || true
  fi
fi

# Also write the key to a .env file in the config volume so PHP can read it
# (app.php reads .env from /var/www/config/.env)
if [ ! -f "${CONFIG_DIR}/.env" ] || ! grep -q "APP_ENCRYPTION_KEY" "${CONFIG_DIR}/.env" 2>/dev/null; then
  echo "APP_ENCRYPTION_KEY=\"${APP_ENCRYPTION_KEY}\"" >> "${CONFIG_DIR}/.env"
fi

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
ADMIN_PASSWORD_HASH=$(php -r 'echo password_hash(getenv("ADMIN_PASSWORD"), PASSWORD_DEFAULT);')
echo "Using admin password hash: ${ADMIN_PASSWORD_HASH}"

# Replace placeholder in SQL files (use a non-/ delimiter to avoid issues with bcrypt hashes)
for sql_file in /usr/local/share/app-migrations/*.sql; do
  if [ -f "$sql_file" ]; then
    sed -i "s|{{ADMIN_PASSWORD_HASH}}|${ADMIN_PASSWORD_HASH}|g" "$sql_file"
  fi
done

# 1) Base schema: if key table (quotes) is missing, load the init schema
if ! mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -N -e \
     "SELECT 1 FROM information_schema.tables WHERE table_schema='${DB_NAME}' AND table_name='quotes' LIMIT 1" | grep -q 1; then
  echo "Applying base schema to '${DB_NAME}'..."
  

  # Run the unified init.sql which contains all modules. TrueNAS/image-based
  # deployments rely on the copy baked into the web image, while local compose
  # can still provide the MySQL entrypoint path as a bind mount.
  INIT_SQL="/docker-entrypoint-initdb.d/01-init.sql"
  if [ ! -f "$INIT_SQL" ]; then
    INIT_SQL="/usr/local/share/app-migrations/init.sql"
  fi
  if [ -f "$INIT_SQL" ]; then
    echo "Applying unified schema: $INIT_SQL"
    if mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -D "${DB_NAME}" < "$INIT_SQL" > /dev/null 2>&1; then
      echo "✅ Base schema applied from init.sql"
    else
      echo "⚠️ Failed to apply init.sql, trying individual migrations..."
      # Fallback to individual migration files
      for migration in /var/www/database/migrations/*.sql; do
        if [ -f "$migration" ]; then
          echo "Applying migration: $(basename "$migration")"
          mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -D "${DB_NAME}" < "$migration" || true
        fi
      done
    fi
  else
    echo "⚠️ No init.sql found at $INIT_SQL"
  fi
fi

# Ensure admin user exists with current password hash (recovery mechanism)
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@project-alpha.local}"
ADMIN_USERNAME="${ADMIN_USERNAME:-admin}"
echo "Ensuring admin user exists with email: ${ADMIN_EMAIL}, username: ${ADMIN_USERNAME}"
# Ensure admin user exists and is linked to the default organization
mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -D "${DB_NAME}" -e "
  INSERT INTO users (email, password_hash, username, role, force_password_reset)
  VALUES ('${ADMIN_EMAIL}', '${ADMIN_PASSWORD_HASH}', '${ADMIN_USERNAME}', 'admin', 0)
  ON DUPLICATE KEY UPDATE password_hash='${ADMIN_PASSWORD_HASH}', email='${ADMIN_EMAIL}', username='${ADMIN_USERNAME}', role='admin', force_password_reset=0, deleted_at=NULL;
" || echo "⚠️  WARNING: admin user upsert failed — admin password may not have been updated this boot."

mysql --skip-ssl -h "${DB_HOST}" -P "${DB_PORT}" -u"${ROOT_USER}" --password="${ROOT_PASSWORD}" -D "${DB_NAME}" -e "
  INSERT INTO user_organizations (user_id, organization_id, role, is_default)
  VALUES (
    (SELECT id FROM users WHERE email='${ADMIN_EMAIL}' LIMIT 1),
    (SELECT id FROM organizations ORDER BY id ASC LIMIT 1),
    'owner',
    1
  )
  ON DUPLICATE KEY UPDATE \`role\`='owner', \`is_default\`=1;
" || true

# 2) Run PHP migration runner BEFORE Apache starts. It tracks applied state
#    in schema_migrations and tolerates per-file failures non-fatally.
echo "Running PHP migration runner (state-tracked, non-fatal errors)..."
php /var/www/src/migrations/run_migrations.php --verbose 2>&1 || echo "WARNING: Migration runner reported errors (non-fatal)"

# 2b) Runtime SQL file (kept as an idempotent fallback / legacy hook)
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

# 5b) Ensure backup directories exist with correct permissions
echo "Creating backup directories..."
BACKUP_DIR="/var/www/backups"
for subdir in daily weekly monthly; do
    if [ ! -d "$BACKUP_DIR/$subdir" ]; then
        mkdir -p "$BACKUP_DIR/$subdir"
    fi
done
chown -R www-data:www-data "$BACKUP_DIR" 2>/dev/null || true
chmod -R 775 "$BACKUP_DIR" 2>/dev/null || true

# 6) Database and config setup complete
# Note: Cron jobs are now handled by the separate 'cron' service in docker-compose.yml
# This web service no longer manages scheduled tasks.

# 7) Optional: harden Apache virtual host when APP_HOST is set
APP_HOST="${APP_HOST:-}"
if [ -n "$APP_HOST" ]; then
  echo "🔒 Hardening Apache for domain: ${APP_HOST}"
  cat > /etc/apache2/conf-available/security-hardening.conf <<EOF
# Deny access by IP, require hostname match
<VirtualHost *:80>
    ServerName ${APP_HOST}
    DocumentRoot /var/www/html
    
    # Security headers
    Header always set X-Content-Type-Options nosniff
    Header always set X-Frame-Options SAMEORIGIN
    Header always set X-XSS-Protection "1; mode=block"
    Header always set Referrer-Policy no-referrer-when-downgrade
    Header always set Content-Security-Policy "script-src 'self' https://js.stripe.com https://static.cloudflareinsights.com; default-src 'self'; img-src 'self' data: blob:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; frame-src https://js.stripe.com https://hooks.stripe.com; connect-src 'self' https://api.stripe.com; object-src 'none'; base-uri 'self'; form-action 'self';"
    Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains" env=HTTPS
    
    # Disable server signature
    ServerTokens Prod
    ServerSignature Off
    
    <Directory /var/www/html>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

# Default: deny direct IP access (optional)
<VirtualHost _default_:80>
    DocumentRoot /var/www/html
    <Location />
        Require all denied
    </Location>
</VirtualHost>
EOF
  a2enmod headers
  a2enconf security-hardening
  echo "✅ Apache hardened for production domain."
fi

echo "✅ Setup complete."
exec apache2-foreground

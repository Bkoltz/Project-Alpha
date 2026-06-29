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

# Database initialization, migrations, schema validation, and administrator
# reconciliation are completed by docker/migrate.sh before this container is
# allowed to start. The web process never performs fail-open schema work.

# Seed config directory with defaults if mounted and empty
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

  # Ensure dedicated log directories exist with correct permissions
  for log_subdir in logs/system logs/cron; do
    full_dir="${CONFIG_DIR}/${log_subdir}"
    if [ ! -d "$full_dir" ]; then
      echo "Creating ${full_dir}..."
      mkdir -p "$full_dir" || true
    fi
    chown -R www-data:www-data "$full_dir" 2>/dev/null || true
    chmod 775 "$full_dir" 2>/dev/null || true
  done

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

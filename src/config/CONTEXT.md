# Configuration Context

Last reviewed: 2026-06-28

This directory initializes database access, application settings, bootstrap checks, and logging.

## Main Files

- `db.php`: builds the PDO connection from `DB_HOST`, `DB_PORT`, and `MYSQL_*`
- `app.php`: merges defaults, mounted settings, environment values, and encrypted `app_config` values
- `bootstrap.php`: idempotent compatibility bootstrap for critical authentication/API structures
- `logging.php`: structured Monolog configuration
- `settings.json`: non-secret fallback defaults when present

The mounted `/var/www/config` directory is preferred in containers. The startup script generates `APP_ENCRYPTION_KEY` when missing, persists it in that volume, and exposes it to PHP.

Stripe and SMTP secrets saved through supported settings are encrypted before database storage. Never place production secrets in repository fallback files.

Every request enters through `public/index.php`, which loads the required configuration. Avoid duplicate bootstrap paths in controllers.

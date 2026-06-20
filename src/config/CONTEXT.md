# src/config — Context

Last updated: 2026-06-20 by Hermes

## What This Is

This folder bootstraps the application. It creates the PDO connection, defines default app settings, loads secrets from `.env`/environment, ensures required database tables exist, and configures Monolog loggers. Every request starts here via `public/index.php`.

## Files

- `db.php` — Builds the MySQL PDO DSN from environment variables and creates a `$pdo` global with `ERRMODE_EXCEPTION` and `FETCH_ASSOC` defaults.
- `bootstrap.php` — Idempotent schema bootstrap. Creates `users`, `login_attempts`, `api_keys`, `api_usage`, `user_2fa`, and `login_2fa_attempts`; adds legacy columns safely.
- `app.php` — Merges default app config with `settings.json` and `.env`. Secrets (Stripe, SMTP, encryption key) are loaded from environment only and never persisted in `settings.json`.
- `settings.json` — Default non-secret JSON settings (brand, contact info, SMTP host, etc.).
- `logging.php` — Monolog setup returning named loggers: `app`, `security`, `audit`, `system`. Writes JSON rotating logs under `logs/`.

## Key Details

- `db.php` uses `DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`/`MYSQL_ROOT_PASSWORD`; falls back to `db`/`project_alpha`/`root`/`rootpass`.
- `app.php` prefers `/var/www/config/settings.json` if the config volume is mounted, otherwise `src/config/settings.json`.
- Timezone is read from `settings.json`/`appConfig['timezone']`; defaults to `UTC`.
- `bootstrap.php` is intentionally fail-closed: if table creation fails it silently catches so public assets still load, but auth will fail later.
- `logging.php` returns an array of loggers, but many parts of the app still use the older `src/utils/logger.php` fallback.
- Stripe keys and SMTP password are encrypted with `crypto.php` when saved through the settings form; the encryption key comes from `APP_ENCRYPTION_KEY` env var only.

## Dependencies

- MySQL 8 via PDO (`ext-pdo_mysql`).
- Monolog (`monolog/monolog`) for structured logging.
- Environment variables: `DB_HOST`, `MYSQL_*`, `APP_ENCRYPTION_KEY`, `STRIPE_*`, `SMTP_*`.
- Read/Write access to `/var/www/config/` in Docker, or `src/config/` fallback.

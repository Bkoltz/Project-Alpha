# cron — Context

Last updated: 2026-06-20 by Hermes

## What This Is

This folder packages the Docker cron service for Project Alpha. It is not the actual PHP cron script directory (those live in `src/cron/`); this directory contains the cron container configuration, schedule, and build metadata.

## Files

- `README.md` — Human-facing documentation of the cron service, schedule, and environment variables.
- `entrypoint.sh` — Bash entrypoint run when the cron container starts. Dumps env vars to `/etc/environment`, waits for the DB, ensures log directories, then runs `cron -f`.
- `crontab` — System crontab that sources `/etc/environment` and invokes the PHP scripts under `src/cron/`.
- `composer.json` — Minimal composer metadata for the cron image (`php >=8.1`, `ext-pdo`, `ext-curl`, `ext-openssl`, PHPMailer).
- `.dockerignore` — Exclusions for the cron Docker build.

## Key Details

- The container runs `cron -f` in the foreground. Cron does **not** inherit env vars, so `entrypoint.sh` writes matching keys to `/etc/environment` and each job line sources it.
- `crontab` schedules are UTC:
  - `0 2 * * *` — `generate_recurring_invoices.php`
  - `0 8 * * *` — `send_invoice_reminders.php`
  - `0 3 * * *` — `auto_terminate_contracts.php`
  - `0 4 * * *` — `link_expiration_checker.php`
  - `0 */6 * * *` — `stripe_reconciliation.php`
  - `0 6 * * *` — `process_audit_schedules.php`
  - `30 2 * * *` — `backup_database.php`
- All job output is appended to `/var/www/logs/cron.log`.
- `entrypoint.sh` waits up to 120 seconds for MySQL using `mysqladmin ping` or a TCP socket test.
- The cron image is built from this folder and copies/duplicates relevant `src/` files at build time.

## Dependencies

- Docker compose references this image/service.
- Requires the same DB credentials as the web service: `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `DB_HOST`, `DB_PORT`.
- Reads/writes `logs/`, `backups/`, and DB tables updated by the scripts in `src/cron/`.
- See `src/cron/CONTEXT.md` for detailed documentation of each scheduled PHP script.

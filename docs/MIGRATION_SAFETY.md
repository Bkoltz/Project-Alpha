# Migration Safety

## When to Re-initialize the DB vs Run Migrations

- **Re-initialize** (`docker compose down -v && docker compose up -d`): Use when the schema change is large and the DB has no production data (staging, dev, or pre-launch). This is the safest path — fresh `init.sql` + migrations run clean.

- **Run migrations**: Use when the DB has production data that must be preserved. The migration runner now takes a pre-migration backup automatically.

## Pre-Migration Backup

The migration runner automatically creates a `mysqldump` backup in `/var/www/backups/pre-migration/` before running pending migrations. Backups are retained for 7 days and cleaned up automatically.

If `mysqldump` is unavailable or the backup fails, the runner logs a warning and continues without a backup (non-fatal).

## Post-Migration Health Check

After migrations run, the runner verifies critical ACL tables and columns exist:

- `roles` table exists
- `role_permissions` table exists
- `user_organizations.role_id` column exists
- `quotes.created_by` column exists
- No rows with NULL `role_id` in `user_organizations`

Issues are logged to stderr and PHP `error_log()`. The application still starts even if the health check finds issues — the issues are surfaced for diagnosis, not blocking.

## Failed Migrations

Failed migrations are **NOT** recorded as "applied" in `schema_migrations`. They will re-run on the next container boot. This ensures:

1. A code fix in the next image push will resolve the issue (the fixed migration re-runs)
2. The issue is surfaced on every boot until fixed (not silently skipped)
3. The `SKIP_MIGRATIONS_ON_BOOT` environment variable remains as an escape valve for emergencies

## REPAIR Logic

The migration runner includes REPAIR logic for ACL migrations (023-027). If these migrations are marked "applied" in `schema_migrations` but the ACL tables don't actually exist (due to a previous buggy seed), the runner removes them from the applied list and re-runs them.

## Escape Valve

Set `SKIP_MIGRATIONS_ON_BOOT=true` in the container environment to skip all migrations on boot. This allows the app to start even if a migration is broken, providing a recovery path without requiring a code rollback.

## Log Directory Structure

```
/config/
  logs/
    system-logs/    ← Application logs (app_log, Monolog)
    cron-logs/      ← Cron job logs (cron_log)
  .encryption_key   ← Auto-generated encryption key
/uploads/
  receipts/         ← Uploaded receipt images (by year/month)
  forms/            ← Uploaded forms and documents (by category)
  signed_contracts/ ← Signed contract PDFs from clients
```

## Dashboard Pages

- **Admin dashboard** (`home.php` at `/?page=home`): Financial dashboard with income/expense charts, requires `financial.view` permission.
- **User dashboard** (`user-dashboard.php` at `/?page=user-dashboard`): Card-based module quick-access for non-admin users, no specific permission required (authenticated users only).

Non-admin users are automatically redirected to the user dashboard on login. The navigation sidebar shows "Dashboard" for both admin and user roles, linking to the appropriate dashboard.
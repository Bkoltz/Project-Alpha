# src/cron — Context

Last updated: 2026-06-19 by Hermes

## What This Is

This folder contains the actual PHP scripts executed by the cron container. Each script is invoked on a schedule defined in `cron/crontab`. They run unattended, use `src/config/db.php` and `src/config/app.php`, write to `error_log`, and update settings/DB timestamps after runs.

## Files

- `backup_database.php` — Pure-PHP gzipped SQL dump to `/var/www/backups/{daily,weekly,monthly}/`. Retention: 7 daily, 4 weekly, 12 monthly.
- `generate_recurring_invoices.php` — Creates invoices for active `long_term` contracts whose `next_invoice_date <= today`. Supports `per_invoice` and `fixed_total` pricing. Also sends configured due-7 and weekly-overdue reminders.
- `send_invoice_reminders.php` — Standalone due-7 and overdue-weekly email reminders using PHPMailer and public links; records rows in `invoice_notifications`.
- `auto_terminate_contracts.php` — Marks `active`/`paused` contracts as `completed` when `end_date < today`.
- `link_expiration_checker.php` — Sets `entity_links.is_expired = 1` for links past `expiration_date`; optionally emails admin; reports links expiring within 7 days.
- `stripe_reconciliation.php` — Fetches Stripe Payment Intents since the last run (max 30 days back), records missing payments, updates invoice status, revokes public links, and marks linked contracts completed.
- `auto_charge_recurring.php` — Off-session Stripe auto-pay for invoices where `clients.auto_pay_enabled = 1` and saved payment method exists. Logs to `auto_pay_log`.
- `process_audit_schedules.php` — Generates and emails scheduled financial audit CSVs for invoices/contracts/quotes; advances `audit_schedules.next_run_at`.

## Key Details

- Every script begins with `require_once __DIR__ . '/../config/db.php'` and usually `app.php`.
- Most scripts check `cron_enabled` in settings and exit early if disabled.
- Invoices created by `generate_recurring_invoices.php` are linked to `contracts` via `invoices.contract_id`, type `long_term`, with doc numbers from `MAX(doc_number)`.
- `generate_recurring_invoices.php` updates `contracts.next_invoice_date`, `last_invoice_date`, `total_invoiced`, and `invoices_generated`; completes contracts when invoice count or end date is reached.
- `auto_charge_recurring.php` uses `StripeService::createPaymentIntentWithMetadata(...)` with `off_session=true` and logs successes/failures to `auto_pay_log`.
- `stripe_reconciliation.php` processes `pa_invoice_id`/`invoice_id` metadata on succeeded Payment Intents, inserts `payments`, updates `invoices.status` to `paid`/`partial`, and revokes public links for fully paid invoices.
- `link_expiration_checker.php` reads `app_config.link_expiration_checker` to enable; otherwise exits.
- `process_audit_schedules.php` builds a single CSV with UTF-8 BOM and summary rows, then emails it via `mailer_send()` with attachments.
- Backups use `gzopen()` and emit `DROP TABLE IF EXISTS` + `CREATE TABLE` + `INSERT` statements for every table.

## Dependencies

- `cron/crontab` and `cron/entrypoint.sh` for scheduling.
- `src/config/db.php`, `src/config/app.php`, `src/utils/mailer.php`, `src/utils/crypto.php`.
- `src/services/StripeService.php`, `src/utils/StripeFeeCalculator.php`.
- Database tables: `contracts`, `invoices`, `invoice_items`, `payments`, `clients`, `public_links`, `entity_links`, `cron_job_runs`, `invoice_notifications`, `auto_pay_log`, `audit_schedules`, `audit_schedule_logs`.
- Environment: MySQL credentials, `APP_ENCRYPTION_KEY`, SMTP/Stripe settings in `appConfig`.
- Directories: `/var/www/backups/`, `/var/www/logs/`, `/var/www/config/` (or project fallback).

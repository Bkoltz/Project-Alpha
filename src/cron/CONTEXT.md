# Scheduled Job Context

Last reviewed: 2026-06-28

These PHP scripts run unattended from `cron/crontab`. They use the same database and application configuration as the web process and record outcomes in logs and, where implemented, `cron_job_runs`.

## Current Jobs

- `generate_recurring_invoices.php`
- `generate_recurring_expenses.php`
- `backup_database.php`
- `auto_terminate_contracts.php`
- `link_expiration_checker.php`
- `daily_link_resolver.php`
- `process_audit_schedules.php`
- `send_invoice_reminders.php`
- `stripe_reconciliation.php`
- `sync_merchant_rate.php`
- `sync_alphaledger.php`

## Rules

- Jobs must be safe to retry and tolerate downtime.
- Use `cron_state` helpers for visible success/failure state.
- Prevent duplicate invoices, payments, reminders, and scheduled reports.
- Keep all date/time assumptions explicit; the installed schedule is UTC.
- Do not log credentials, full third-party payloads, or customer documents.
- Test a job manually inside the cron image before relying on its schedule.
- Update `cron/README.md` whenever timing or responsibility changes.

Document tables are unified; long-term and on-demand behavior is selected by type columns and linked through `contract_id`.

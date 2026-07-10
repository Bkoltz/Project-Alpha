# Project Alpha Cron Service

The cron image runs Project Alpha's scheduled PHP jobs independently from Apache. The current schedule is installed from `cron/crontab` and uses the PA system timezone loaded from `app_config.timezone` when the cron container starts.

On container startup, the entrypoint runs a scheduled backup catch-up check and `stripe_reconciliation.php --startup` before starting cron. The backup check creates today's missing backup when the configured backup time has already passed. Stripe reconciliation catches recent payments after downtime, while the normal six-hour schedule continues to reconcile missed webhooks.

## Installed Schedule

| Local schedule | Script | Purpose |
|---|---|---|
| Daily 02:00 | `generate_recurring_invoices.php` | Generate due long-term invoices and catch up missed periods |
| Daily 02:15 | `generate_recurring_expenses.php` | Generate due recurring expenses once per scheduled occurrence |
| Hourly at :30 | `backup_database.php --scheduled` | Creates rotating backups when the configured local backup hour matches |
| Daily 03:00 | `auto_terminate_contracts.php` | Complete contracts whose configured end date has passed |
| Daily 04:00 | `link_expiration_checker.php` | Expire public document links |
| Every 6 hours | `stripe_reconciliation.php` | Recover Stripe payments missed by webhooks or downtime |
| Daily 05:00 | `sync_merchant_rate.php` | Calculate the observed Stripe processing rate |
| Daily 06:00 | `process_audit_schedules.php` | Generate and deliver scheduled audit exports |
| Daily 08:00 | `send_invoice_reminders.php` | Send enabled due and overdue reminders |

All output is appended to `/var/www/config/logs/cron/cron.log` in the cron container.

If the PA system timezone is changed in Settings, restart or recreate the cron container so the daemon reloads `/etc/localtime`. Individual PHP jobs also load `config/app.php`, so their date math uses the configured timezone.

## Runtime

The multi-stage root `Dockerfile` builds the cron image and copies the current `src/`, Composer dependencies, migrations, crontab, and entrypoint into the image. Application source is not bind-mounted by the published Compose configuration; rebuild or pull a new cron image after PHP cron changes.

Persistent volumes provide:

- `/var/www/config`: settings, encryption key, and logs
- `/var/www/backups`: generated backups
- `/var/www/src/uploads`: shared user uploads

## Commands

```bash
# Check service state
docker compose ps cron

# Inspect the installed schedule
docker compose exec cron cat /etc/cron.d/project-alpha

# Follow cron output
docker compose exec cron tail -f /var/www/config/logs/cron/cron.log

# Run a job manually
docker compose exec cron php /var/www/src/cron/generate_recurring_invoices.php
docker compose exec cron php /var/www/src/cron/generate_recurring_expenses.php
```

## Application Settings

The container schedule always starts with the service. Individual scripts also honor application settings where applicable, including:

- `cron_enabled`
- invoice due and overdue reminder toggles
- invoice email-on-generation toggle
- Stripe and SMTP configuration

Database backups are infrastructure protection and do not depend on the automatic-invoice `cron_enabled` setting.

## Troubleshooting

1. Confirm the cron service is running and connected to MySQL.
2. Review `/var/www/config/logs/cron/cron.log`.
3. Check `cron_job_runs` for the last success or failure.
4. Run the affected PHP script manually in the cron container.
5. Confirm the configuration and encryption-key volumes are mounted.
6. Confirm the deployed cron tag matches the intended branch.
7. Confirm `/etc/cron.d/project-alpha` includes `/usr/local/bin` in `PATH`; otherwise the official PHP image's executable is not available to cron jobs.

Do not paste production logs into a public issue without removing credentials and customer information.

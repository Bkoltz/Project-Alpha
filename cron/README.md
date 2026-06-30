# Project Alpha - Cron Service

Docker image that runs scheduled background tasks for Project Alpha.

## Cron Jobs

| Schedule | Script | Description |
|----------|--------|-------------|
| Daily 2:00 AM UTC | `generate_recurring_invoices.php` | Creates invoices for active long-term contracts on their billing schedule |
| Daily 2:15 AM UTC | `auto_charge_recurring.php` | Charges eligible auto-pay invoices after recurring invoices are generated |
| Daily 2:30 AM UTC | `backup_database.php` | Creates rotating daily, weekly, and monthly database backups |
| Daily 3:00 AM UTC | `auto_terminate_contracts.php` | Marks expired contracts as completed |
| Daily 4:00 AM UTC | `link_expiration_checker.php` | Flags expired public links |
| Daily 6:00 AM UTC | `process_audit_schedules.php` | Generates and emails scheduled financial audit CSVs |
| Daily 8:00 AM UTC | `send_invoice_reminders.php` | Sends 7-day-due and weekly-overdue email reminders |
| Every 6 hours | `stripe_reconciliation.php` | Reconciles Stripe payments missed during downtime |
| Daily 5:00 AM UTC | `sync_merchant_rate.php` | Computes the actual blended Stripe merchant rate from recent balance transactions and stores it in `app_config` |

All job output is appended to `/var/www/config/logs/cron/cron.log` inside the cron container.

## Environment Variables

Passed from `docker-compose.yml`:

- `MYSQL_DATABASE`
- `MYSQL_USER`
- `MYSQL_PASSWORD`
- `MYSQL_ROOT_PASSWORD`
- `DB_HOST`
- `DB_PORT`
- `APP_*`, `STRIPE_*`, and `SMTP_*` values when present

The entrypoint writes these values to `/etc/environment` because cron jobs do not inherit the container environment automatically.

## Build & Run

Use Docker Compose from the Project Alpha repo root:

```bash
docker compose up -d --build cron
```

To inspect the installed schedule:

```bash
docker compose exec cron cat /etc/cron.d/project-alpha
```

To follow logs:

```bash
docker compose exec cron tail -f /var/www/config/logs/cron/cron.log
```

## Source Files

The cron container mounts the same live source tree as the web container:

```yaml
- ./src:/var/www/src
```

Do not copy PHP source into this image. Rebuild the cron image when `cron/Dockerfile`, `cron/crontab`, `cron/entrypoint.sh`, or Composer dependencies change; ordinary PHP cron script changes are picked up through the mounted `src/` volume.

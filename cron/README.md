# Project Alpha – Cron Service

Docker image that runs scheduled background tasks for Project Alpha.

## Cron Jobs

| Schedule | Script | Description |
|----------|--------|-------------|
| Daily 2:00 AM UTC | `generate_recurring_invoices.php` | Creates invoices for active long-term contracts on their billing schedule |
| Daily 8:00 AM UTC | `send_invoice_reminders.php` | Sends 7-day-due and weekly-overdue email reminders |
| Daily 3:00 AM UTC | `auto_terminate_contracts.php` | Marks expired contracts as completed |
| Daily 4:00 AM UTC | `link_expiration_checker.php` | Flags expired public links |
| Every 6 hours | `stripe_reconciliation.php` | Reconciles Stripe payments missed during downtime |

## Environment Variables

Passed from `docker-compose.yml` (same DB credentials as the web service):

- `MYSQL_DATABASE`
- `MYSQL_USER`
- `MYSQL_PASSWORD`
- `MYSQL_ROOT_PASSWORD`
- `DB_HOST`
- `DB_PORT`

## Build & Run

```bash
# Build locally
docker build -t bkoltz/project-alpha-cron:latest .

# Push to Docker Hub
docker push bkoltz/project-alpha-cron:latest
```

Or use `docker compose up` from the Project-Alpha repo, which references this image.

## Keeping Source Files in Sync

The PHP source files under `src/` are copied from Project Alpha's main repo.
When you update cron scripts, config, or shared utilities in Project Alpha,
copy the relevant files here and rebuild the image.

# Scheduled Workflow

The dedicated cron container runs background operations on a fixed UTC schedule. These include recurring invoice generation, recurring auto-charge attempts, rotating backups, contract expiration, public-link expiration, scheduled audits, invoice reminders, Stripe reconciliation, and merchant-rate synchronization.

Application settings enable or disable applicable notification and billing behavior; they do not rewrite the container's installed crontab.

See [Cron Service](../cron/README.md) for the authoritative schedule and troubleshooting commands.

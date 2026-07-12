---
title: Deployment
description: How Project Alpha is deployed and operated.
---

# Deployment

Project Alpha is commonly deployed with Docker Compose or as a TrueNAS Scale Custom App using published GHCR images.

## Services

| Service | Purpose |
|---|---|
| `web` | PHP and Apache application runtime |
| `db` | MySQL database |
| `cron` | Scheduled jobs, reminders, backups, and reconciliation |
| `migrate` | One-shot database initialization and migration validation |

## Basic Docker Flow

```bash
git clone https://github.com/ledgetoptechnologies/Project-Alpha.git
cd Project-Alpha
docker compose pull
docker compose up -d
```

Before first start, replace both database passwords in Compose configuration. No `.env` file or administrator environment variable is required. On a clean database, open PA and complete the first-time setup form to create the initial normal administrator.

## Administrator Recovery

PA does not keep a permanent default administrator. If email recovery is unavailable, a Docker operator can issue a one-time temporary password for an existing active administrator:

```bash
docker compose exec web php bin/admin-recovery.php admin@example.com
```

Use either the administrator username or email. The command refuses non-admin and inactive accounts, revokes existing sessions and password-reset tokens, records an audit event, and forces a password change. The temporary password is printed once and is not logged.

Lost TOTP requires a separate explicit operation:

```bash
docker compose exec web php bin/admin-recovery.php admin@example.com --reset-totp
```

This resets TOTP, records a separate audit event, and requires fresh enrollment after the password change. Do not use `--reset-totp` for password-only recovery.

## TrueNAS

Use the published GHCR images and separate persistent volumes for database data, uploads, config, and backups. Keep staging and production volumes separate.

## Public Access

Put PA behind HTTPS before using public links, Stripe webhooks, or client-facing pages. Set the application domain in **Settings > System** so emails and public links use the correct host.

## Upgrade Safety

Validate new images in staging, confirm backups, review migration notes, then deploy production. Publishing an image does not automatically update a TrueNAS Custom App.

When upgrading from a release that used `ADMIN_PASSWORD`, deploy this release first and confirm `migrate` exits successfully. Only then remove `ADMIN_PASSWORD`, `ADMIN_EMAIL`, and `ADMIN_USERNAME` from the host's Compose configuration and recreate the services. Existing administrator accounts and password hashes are preserved; the upgrade intentionally invalidates pre-upgrade browser sessions once so they are re-established with the versioned session model.

When an upgrade changes scheduled jobs, pull and recreate the `cron` service as well as `web`. Verify `/var/www/config/logs/cron/cron.log` and confirm Settings > Backup records a successful `backup_database` run with a file under `/var/www/backups/daily`.


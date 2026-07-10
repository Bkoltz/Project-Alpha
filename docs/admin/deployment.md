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

Before first start, replace all default passwords in Compose configuration.

## TrueNAS

Use the published GHCR images and separate persistent volumes for database data, uploads, config, and backups. Keep staging and production volumes separate.

## Public Access

Put PA behind HTTPS before using public links, Stripe webhooks, or client-facing pages. Set the application domain in **Settings > System** so emails and public links use the correct host.

## Upgrade Safety

Validate new images in staging, confirm backups, review migration notes, then deploy production. Publishing an image does not automatically update a TrueNAS Custom App.

When an upgrade changes scheduled jobs, pull and recreate the `cron` service as well as `web`. Verify `/var/www/config/logs/cron/cron.log` and confirm Settings > Backup records a successful `backup_database` run with a file under `/var/www/backups/daily`.


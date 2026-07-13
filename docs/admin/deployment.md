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
| `worker` | Durable background and maintenance jobs |
| `cron` | Scheduled jobs, reminders, backups, and reconciliation |
| `migrate` | One-shot database initialization and migration validation |

## Basic Docker Flow

```bash
git clone https://github.com/ledgetoptechnologies/Project-Alpha.git
cd Project-Alpha
docker compose pull
docker compose up -d
```

Before first start, replace both database passwords in Compose configuration. No administrator environment variable is required. On a clean database, open PA and complete the first-time setup form to create the initial normal administrator.

`docker-compose.yml` is the only tracked deployment definition. It contains the
production image tags, port, service settings, and named volumes directly.
Environment-specific copies belong to the deployment host, not the repository.

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

Upgrades and migrations do not create, replace, or reveal TOTP backup codes.
Existing TOTP enrollment continues unchanged. If an enrolled user still has
their authenticator but no saved backup codes, they can use **My Account > Set
Up 2FA > Regenerate Backup Codes** and confirm with a current TOTP code. If an
administrator has neither the authenticator nor a backup code, use the explicit
`--reset-totp` recovery option above.

## Backup Encryption

`BACKUP_ENCRYPTION_KEY` is optional and does not affect startup or migrations.
When it is empty, scheduled and manual backups still run, but their contents
are not encrypted by PA. To enable application-level AES-256 archive
encryption, place the same strong, stable value in both the `web` and `cron`
service definitions and store it outside the server in a password manager.

The key is not written to the database, generated automatically, or recoverable
by PA. Changing or losing it prevents PA from opening backups encrypted with
the old value. Existing unencrypted backups are not retroactively encrypted,
and the required pre-migration safety dump remains a compressed `.sql.gz` file
on the backup volume rather than an encrypted application archive.

## TrueNAS

Paste the canonical `docker-compose.yml` into the TrueNAS Custom App. Its
default named volumes work as-is. To use datasets instead, edit the volume
sources in the YAML pasted into TrueNAS, for example:

```yaml
- /mnt/tank/apps/project-alpha/uploads:/var/www/src/uploads
- /mnt/tank/apps/project-alpha/config:/var/www/config
- /mnt/tank/apps/project-alpha/backups:/var/www/backups
- /mnt/tank/apps/project-alpha/db:/var/lib/mysql
```

Replace `/mnt/tank` with the actual pool path. Keep staging and production
project names, credentials, ports, and storage paths separate.

## Public Access

Put PA behind HTTPS before using public links, Stripe webhooks, or client-facing pages. Set the application domain in **Settings > System** so emails and public links use the correct host.

## Upgrade Safety

Validate new images in staging, confirm backups, review migration notes, then deploy production. Publishing an image does not automatically update a TrueNAS Custom App.

For an existing production installation:

1. Take and verify an external backup before changing Compose.
2. Preserve the current Compose project/app name, database credentials, and
   every existing volume or TrueNAS dataset mapping. Changing those mappings
   can make PA start against a new empty database even though the old data still
   exists elsewhere.
3. Add the `worker` service while retaining the existing `web`, `cron`, `db`,
   `migrate`, upload, config, backup, and database storage mappings.
4. Set `BACKUP_ENCRYPTION_KEY` in both `web` and `cron` only if you have safely
   stored the chosen key. It may remain empty for the upgrade.
5. Run `docker compose pull` followed by `docker compose up -d` without `-v`.
6. Check `docker compose ps -a` and `docker compose logs migrate`; `migrate`
   must exit with status 0 before `web`, `worker`, and `cron` are considered
   ready.

When upgrading from a release that used `ADMIN_PASSWORD`, deploy this release first and confirm `migrate` exits successfully. Only then remove `ADMIN_PASSWORD`, `ADMIN_EMAIL`, and `ADMIN_USERNAME` from the host's Compose configuration and recreate the services. Existing administrator accounts and password hashes are preserved; the upgrade intentionally invalidates pre-upgrade browser sessions once so they are re-established with the versioned session model.

When an upgrade changes scheduled or background jobs, pull and recreate `web`, `worker`, and `cron`. Verify `/var/www/config/logs/cron/cron.log` and confirm Settings > Backup records a successful `backup_database` run with a file under `/var/www/backups/daily`.


# TrueNAS Scale Deployment

This guide covers Project Alpha as a TrueNAS Scale Custom App using published GHCR images.

## Images and Ports

| Environment | Web image | Cron image | Host port |
|---|---|---|---|
| Production | `ghcr.io/ledgetoptechnologies/project-alpha:latest` | `ghcr.io/ledgetoptechnologies/project-alpha:cron-latest` | `1627` |
| Staging | `ghcr.io/ledgetoptechnologies/project-alpha:dev` | `ghcr.io/ledgetoptechnologies/project-alpha:cron` | `1628` |

Keep the GHCR packages readable by TrueNAS. Production and staging must use separate credentials and named volumes.

## Persistent Volumes

Preserve four data sets for each environment:

- MySQL data
- Project Alpha uploads
- Project Alpha configuration and encryption key
- Project Alpha backups

Do not reuse these volumes between staging and production.

## Install

1. Create a Custom App using the appropriate Compose file.
2. Replace every `changeme` password with a unique value.
3. Confirm the web, cron, and database services use the same database credentials.
4. Pull the images and start the application.
5. Open the environment's host port and sign in as `admin@project-alpha.local`.
6. Configure the public application URL, timezone, organization, email, and billing settings.
7. Put the service behind an HTTPS reverse proxy or tunnel before public use.

The database port is not published by the repository's Compose files. Keep it internal.

## Staging

Use staging to validate image startup, migrations, document workflows, public links, Stripe test-mode webhooks, SMTP delivery, cron jobs, and backups.

Never copy live credentials into staging. If production data is needed for a test, use a protected, sanitized copy.

## Upgrade

1. Review the release or pull request and its migrations.
2. Confirm a recent backup and protected encryption key.
3. Pull and redeploy staging.
4. Complete the affected workflow and review logs.
5. Pull and redeploy production manually.
6. Verify the footer version, login, database connectivity, cron logs, and one focused business workflow.

Publishing a GHCR image does not automatically update a TrueNAS Custom App.

## Backup and Recovery

The cron service creates application database backups, but an operator should also protect the TrueNAS data sets. A complete recovery requires the database, uploads, configuration volume with the original encryption key, and deployment configuration.

Practice restoring to a separate environment. A backup that has never been restored is only a hopeful file.

## Troubleshooting

```bash
docker compose ps
docker compose logs web
docker compose logs cron
docker compose logs db
```

Confirm image tags, matching database passwords, persistent mounts, MySQL connectivity, proxy headers, public application URL, and Stripe test/live mode.

Do not commit a pasted TrueNAS configuration if it contains real credentials.

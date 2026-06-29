# Project Alpha Operational Context

Last reviewed: 2026-06-28

This file records public, non-secret deployment conventions. Credentials, private addresses, customer information, and access instructions must remain outside the repository.

## Deployment Topology

- Production runs as a TrueNAS Scale Custom App on host port `1627`.
- Staging runs separately on host port `1628`.
- Production uses `:latest` and `:cron-latest` images.
- Staging uses `:dev` and `:cron` images.
- Production and staging have different databases, named volumes, passwords, and encryption keys.
- TrueNAS redeployment remains a manual operator action after an image is published.
- GHCR packages must remain readable by the deployment environment.

Do not add real hostnames, private IP addresses, passwords, tokens, or remote-access instructions to this file.

## Production Data Rules

1. Every schema change requires an idempotent migration in `database/migrations/`.
2. Add the next immutable sequential file under `database/migrations/`; do not rewrite the 0.5.0 baseline.
3. Use safe defaults or nullable columns for existing data.
4. Test migrations against staging before production.
5. Take and verify a restorable backup before deployment.
6. Never edit production tables manually as part of normal deployment.

## Persistent Data

The deployment must preserve:

- MySQL data volume
- Upload volume
- Configuration volume, including the application encryption key
- Backup volume

Losing the configuration encryption key can make encrypted settings unrecoverable. Back it up separately from the database while protecting it as a secret.

## Image and Branch Flow

| Branch | Web image | Cron image | Intended environment |
|---|---|---|---|
| `dev` | `:dev` | `:cron` | Staging |
| `main` | `:latest` | `:cron-latest` | Production |

Pull requests to `main` must pass the configured checks. Publishing an image does not automatically redeploy TrueNAS.

## Release Checklist

1. Confirm the linked GitHub issue and expected behavior.
2. Verify CI and relevant local tests.
3. Review migrations and backup requirements.
4. Deploy to staging and complete the affected workflow.
5. Review web, cron, and database logs without copying secrets into GitHub.
6. Merge through protected `main`.
7. Pull and redeploy production manually.
8. Run a focused production smoke test with non-sensitive data.
9. Confirm scheduled jobs and backups remain healthy.

## Incident Notes

Store operational incident details in an access-controlled system. A public GitHub issue may contain a sanitized summary, reproduction steps, affected version, and resolution, but must not contain credentials, customer data, private infrastructure details, or full production logs.

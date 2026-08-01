---
title: Documentation Source
description: Source index for the Project Alpha documentation site.
---

# Documentation Source

The published documentation site starts at [Project Alpha Documentation](./).

This folder is organized for GitHub Pages:

| Folder | Purpose |
|---|---|
| `getting-started/` | First-time orientation and setup path |
| `workflows/` | Day-to-day PA workflows |
| `admin/` | Deployment, settings, security, backups, Stripe, and tax rates |
| `reference/` | Focused reference pages |
| `archive/` | Historical plans, audits, implementation notes, and older runbooks |

For invoice automation rollout, migrations 0058/0059, staging validation, observability, and incident backfill, see the [Invoice Automation Staging Runbook](INVOICE_AUTOMATION_STAGING_RUNBOOK.md).

For the isolated general-recipient invoice release, migration 0060, privacy checks, Stripe test-mode acceptance, and the seven-day paid receipt gate, see the [General-Recipient Invoice Release Runbook](GENERAL_RECIPIENT_INVOICE_RELEASE.md).

The site uses Jekyll, so do not add `.nojekyll` unless the publishing strategy changes.

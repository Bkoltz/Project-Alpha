---
title: Admin Overview
description: Administrative setup areas for Project Alpha.
---

# Admin Overview

PA administration is mostly handled from Settings plus the deployment environment that hosts the app.

## Admin Areas

| Area | Purpose |
|---|---|
| System | Business identity, public domain, timezone, logo, and SMTP |
| Billing | Payment methods, Stripe, surcharges, net terms, and receipt behavior |
| Documents | Terms, document settings, custom fields, and public-link behavior |
| Links | File and external-link resolver settings |
| Taxes | Manual tax rates and imported jurisdiction data |
| Notifications | Cron, admin email notifications, reminders, and alert timing |
| Backup | Backup status, retention, and recovery controls |
| Permissions | Roles, permissions, and user access |
| Workforce | Employee PA accounts, employment state, pay rates, and project assignments |
| Timekeeping and Approvals | Timers, breaks, review, correction revisions, and immutable approval snapshots |

## Recommended Order

1. Configure [settings](settings.html).
2. Confirm [backups](backups.html).
3. Configure [security](security.html).
4. Add [Stripe](payments-stripe.html), if needed.
5. Import [tax rates](tax-rates.html), if needed.
6. Verify deployment and cron behavior.
7. Configure [Workforce modules](workforce-modules.html), employees, assignments, and rates.


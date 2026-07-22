---
title: External Operations Integration
description: Assignment-driven synchronization from Project Alpha to a deployment-specific operations application.
---

# External Operations Integration

This optional module projects operational records into a separate, authenticated, read-only application. Project Alpha remains the only editor for Projects, Operations, Tasks, teams, and assignments.

## Company structure and access

A **Business Unit** represents a division, branch, region, department, or crew. Manage units under **Settings > Business > Business units & divisions**. Each Project can have one Unit; its Operations and Tasks inherit that Unit.

Manage work on the Project’s **Team & Work** section:

- Project Team membership is the canonical access and assignment boundary.
- A worker must be an active Team member before being assigned to an Operation or Task.
- A Task may have several assignees.
- An active Project Team membership automatically grants the worker external Project context.
- Ending the final active Project membership revokes automatic access unless a manual exception remains.
- Open Operations or Tasks must be reassigned, completed, or cancelled before a Team membership can end.

External administrators see all synchronized records. Other workers see assigned Project context, their assigned Operations, and their assigned Tasks. A manual exception can provide read-only oversight for selected Business Units; only a Project Alpha administrator can receive global access.

## Configure a deployment

Open **Settings > System & Integrations > Custom integrations**. Configure a deployment-specific display label, signed-event URL, Cloudflare Access service-token credentials, and HMAC secret. Set a stable application key such as `field_operations`; use the same `APPLICATION_KEY` in the provisioning receiver and snapshot importer. No application key or display label is fixed by Project Alpha.

Use a dedicated Project Alpha API key with only the stable `ops.sync.read` scope. The UI describes this as external operations synchronization, but the scope identifier remains stable for compatibility.

Secrets are encrypted with Project Alpha’s persisted application encryption key. Passwords, pay rates, financial details, API secrets, private tokens, and integration secrets are never included in the operational projection.

## Delivery contract

Project Alpha writes signed, idempotent change events for Business Units, Projects, Team membership, Operations, Operation assignments, Tasks, Task assignments, and entitlements. Events include an event ID, source timestamp, schema version, configured application key, and HMAC signature. The receiver ignores duplicates and out-of-order changes.

The external application also performs a daily reconciliation using:

```text
GET /api/v1/ops/snapshot?page=1&limit=500
```

Follow `next_page` while `has_more` is true. The snapshot includes Business Unit-aware Projects and the multi-worker `task_assignments` collection. It is the recovery authority if an incremental event is delayed or missed.

The integration status card reports queued deliveries, retry errors, and the last successful delivery. After deployment or a configuration change, run a full snapshot reconciliation and reconcile the Cloudflare Access group.

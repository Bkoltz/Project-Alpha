---
title: External Operations Integration
description: Assignment-driven synchronization from Project Alpha to a deployment-specific operations application.
---

# External Operations Integration

This optional module projects operational records into a separate, authenticated, read-only application. Project Alpha remains the only editor for Projects, Operations, Tasks, teams, and assignments.

## Company structure and work planning

A **Business Unit** represents a division, branch, region, department, or crew. Manage Units under **Settings > Business > Business units & divisions**. Add existing PA users as Members or Heads and choose one primary Unit per user. These are organizational labels: they do not grant PA permissions, workforce review scope, or external-application access.

Each Project can have one Unit, and its Operations and Tasks inherit that Unit. Manage work in the Project's **Team & Work** section:

- Select one primary Project Manager. The manager is kept on the Project Team and can supply the default Business Unit for a new Project.
- A worker must be an active Team member before being assigned to an Operation or Task.
- A Task may have several assignees.
- Open Operations or Tasks must be reassigned, completed, or cancelled before a Team membership can end.
- The current Project Manager must be replaced or cleared before their Team membership can end.

## Access model

External access is an explicit user allowlist managed either under **Custom integrations** or on the PA account. Project Team and Business Unit membership never grants sign-in access by itself.

A selected user whose exact PA role is `admin` becomes a global external administrator. Every other selected role becomes an assignment-scoped operator. Project Team membership or Project Manager status provides Project context; direct Operation and Task assignments provide those records. A selected operator with no assignments sees an empty operational workspace. Business Units remain synchronized as Project metadata and are not authorization scopes.

## Configure a deployment

Open **Settings > System & Integrations > Custom integrations**. Configure a deployment-specific display label, signed-event URL, Cloudflare Access service-token credentials, and HMAC secret. Set a stable application key such as `field_operations`; use the same `APPLICATION_KEY` in the provisioning receiver and snapshot importer. No application key or display label is fixed by Project Alpha.

Use a dedicated Project Alpha API key with only the stable `ops.sync.read` scope. The UI describes this as external operations synchronization, but the scope identifier remains stable for compatibility.

Secrets are encrypted with Project Alpha's persisted application encryption key. Passwords, pay rates, financial details, API secrets, private tokens, and integration secrets are never included in the operational projection.

## Delivery contract

Project Alpha writes signed, idempotent change events for Business Units, Projects, Team membership, Operations, Operation assignments, Tasks, Task assignments, and entitlements. Events include an event ID, source timestamp, schema version, configured application key, and HMAC signature. The receiver ignores duplicates and out-of-order changes.

The external application also performs a daily reconciliation using:

```text
GET /api/v1/ops/snapshot?page=1&limit=500
```

Follow `next_page` while `has_more` is true. The snapshot includes Project Managers, Business Unit-aware Projects, and the multi-worker `task_assignments` collection. It is the recovery authority if an incremental event is delayed or missed.

The integration status card reports queued deliveries, retry errors, and the last successful delivery. After deployment or a configuration change, run a full snapshot reconciliation and reconcile the Cloudflare Access group.

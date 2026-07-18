---
title: External Operations Integration
description: Opt-in synchronization from Project Alpha to a deployment-specific operations dashboard.
---

# External Operations Integration

External Operations is an optional advanced module for installations that run
a separate authenticated operations dashboard. It is disabled by default and
is kept under the administrator-only **Settings > Custom integrations** page.
No integration-specific fields appear in the standard Docker Compose file.
This is an integration boundary, not security by obscurity: ordinary PA
permission checks still control access.

Project Alpha remains the source of truth for users, application entitlements,
projects, operations, tasks, assignments, and calendar dates. The external
application consumes a read-only projection. It may own delivery workflows,
airspace checks, cached projections, audit history, and a protected break-glass
owner that PA can never provision.

## Enable a Deployment

Open **Settings > Custom integrations** as an installation administrator. Enter
the display label, provisioning webhook URL, Cloudflare Access service-token
credentials, and a 32-character-or-longer shared HMAC secret. The synchronization
contract fixes the application key as `ltds_ops`. Save the form
with **Enable this custom integration** selected.

Open-source installations may leave this personal integration disabled, or use
their own display label, endpoint, and credentials with a compatible receiver.
PA stores the non-secret configuration in `app_config`. The Access credentials and HMAC secret are
stored together as an AES-256-GCM encrypted value and are never displayed
after saving. Blank secret inputs retain the existing encrypted values.

Run the normal migration process after upgrading. The migration adds generic
entitlement, outbox, operation, assignment, and task tables. Enabling the
feature then reveals the operational controls within **Settings > Custom integrations** for administrators with
`settings.manage`. PA's standard persisted application encryption key must be
available, as it is for other encrypted settings such as SMTP and Stripe.

## Access and Provisioning

The normal user create/edit form includes an **LTDS Operations access** checkbox.
Its `application_entitlements.enabled` value is the explicit ACL. PA derives the
external role instead of allowing a separate role override: the exact PA
`admin` role becomes global `role-admin`; every other PA role, including
`owner`, becomes business-unit-scoped `role-operator`. Employee business-unit
selections are retained through promotion and demotion. Disabling or deleting a
PA user creates an effective revocation event without erasing the saved ACL. An
inactive or terminated worker profile is also treated as inactive during event
generation and snapshot reconciliation.

Entitlement changes and relevant user changes are written to a transactional
outbox in the same database transaction. The cron sender retries due events
with exponential backoff. Every request includes a Cloudflare Access service
token, a stable event ID, a UTC timestamp, and an HMAC-SHA256 signature over
`timestamp + "." + raw_request_body`. The receiver must verify Access, reject
stale timestamps, compare the HMAC in constant time, and treat the event ID as
an idempotency key.

## Snapshot API

Create a dedicated PA API key with only the `ops.sync.read` scope. API-key
authentication creates a service principal and does not impersonate an
administrator. The external worker can reconcile its projection with:

```text
GET /api/v1/ops/snapshot?page=1&limit=500
```

Continue with `next_page` while `has_more` is true. Each page contains the
least-privilege user and business-unit projection plus clients, organizations,
projects, assignments, locations, application entitlements, operations, tasks,
and normalized calendar events. Password hashes, passkey material, payment
secrets, private file tokens, pay rates, and API-key secrets are excluded.

## Operations and Tasks

The same settings module provides initial create/edit workflows for operations
and tasks. These records are edited in PA and projected outward through the
snapshot. The external dashboard should link users back to PA for edits rather
than accepting competing writes.

## Operational Checks

The settings page reports missing configuration fields, pending/retrying outbox
events, the most recent successful delivery, and the latest bounded error. Use
**Retry due events now** after correcting configuration. Monitor the cron job
named `external_ops_outbox`, and periodically reconcile the snapshot even when
webhook delivery is healthy.

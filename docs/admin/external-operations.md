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

Use **Grant external operations access** (or the configured application name shown by the deployment) as the authoritative provisioning action. It activates a merely inactive PA account, enables the configured application entitlement, derives the external role, and queues one signed change event in a single transaction. Deleted or terminated users must be restored separately. **Revoke external operations access** disables only the external entitlement; it does not disable the PA account or remove Project assignments.

The access panel distinguishes the PA account state, effective external access, derived role, and latest delivery status. A legacy `manual_access` value by itself is never treated as effective access: the explicit selection, enabled entitlement, and active account must all agree. Repeating an already-completed grant or revoke is a safe no-op and does not queue a duplicate event.

| Access action or state | Result |
|---|---|
| Grant an active account | Enables the configured entitlement and queues one `application_entitlement.changed` event |
| Grant a merely inactive account | Reactivates the PA account and worker records, enables the entitlement, and queues one consistent changed event |
| Grant a deleted or terminated account | Refused until the account or worker is restored through its normal administrative workflow |
| Repeat an effective grant | Successful no-op; no contradictory or duplicate event is queued |
| Revoke access | Disables the external entitlement and queues `application_entitlement.revoked`; the PA account remains active |
| Repeat a completed revoke | Successful no-op; no duplicate revocation is queued |

Effective sign-in access requires all of the following: the PA account is active,
the configured application entitlement is enabled, and the latest projection is
not a revocation. Account authentication, external role, and work visibility are
separate concerns: granting access permits sign-in but never expands Project,
Operation, or Task assignments.

A selected user whose exact PA role is `admin` becomes a **Global external administrator** (`role-admin`). Every other selected role, including PA `owner`, becomes an **Assigned-work operator** (`role-operator`). Project Team membership or Project Manager status provides Project context; direct Operation and Task assignments provide those records. A selected operator with no assignments sees an empty operational workspace. Business Units remain synchronized as Project metadata and are not authorization scopes.

## Configure a deployment

API-key pull synchronization and signed outbound delivery are separate features.
An existing API key (including one with `ops.sync.read`) does not configure or
enable the outbound outbox sender. The pull snapshot remains available when
outbound delivery is disabled or paused, provided its normal API key and stable
application key requirements are met.

Open **Settings > System & Integrations > Custom integrations**. This single surface contains the deployment-specific display label, signed-event URL, service authentication credentials, HMAC secret, explicit Project Alpha account access, and synchronization health. Set a stable application key such as `external_application`; use the same key in the provisioning receiver and snapshot importer. No application key or display label is fixed by Project Alpha. The open-source display-name fallback is **External operations**; a deployment may replace it with its own product name.

Portal projection profiles, workspace/principal records, scoped allowlists,
runtime gates, recovery, and signing remain backend compatibility contracts and
are not editable in normal Settings navigation. The External Operations card
shows only client-portal health and one idempotent reconcile/repair action. The
ordinary organization and client pages contain the relevant portal-login
revoke/restore action.

Project Alpha automatically publishes login eligibility for an active human
contact only when it has one valid canonical email that is unique among active
client records. Missing, invalid, or duplicate email, an unclassified standalone
record, and a conflict with a principal outside this managed workflow all fail
closed as **review required**. Organization client rows are contact records;
standalone records must explicitly be consumer records. An administrator revoke
is durable and is not undone by reconciliation. Archiving or deleting a client,
or revoking its organization root, publishes the corresponding disabled state
and tombstones.

Eligibility is not identity proof and is not content access. Project Alpha never
writes an identity-provider issuer/subject binding. The consuming portal must
bind the principal only after an exact verified sign-in assertion, and Operations
must still grant a folder or delivery explicitly. The automatic capabilities are
limited to opening the workspace, reading its directory, and viewing deliveries;
they cannot create shares or infer access to any stored file.

Project Alpha does not accept or infer an identity-provider subject from email.
The consuming portal verifies a live assertion and owns the issuer/subject
binding. Matching names, addresses, CRM contacts, primary contacts, and public
links are never identity bindings or grants.

Outbound delivery is ready only when the administrator has requested it and all
five delivery values are available: application key, signed event URL, Access
service-token ID, Access service-token secret, and HMAC secret. The encrypted
credential payload must also be readable with the deployment's persisted
application encryption key. Timeout and maximum-attempt settings are bounded
but are not readiness predicates. Keep outbound delivery disabled until the
receiver contract is deployed. If an older or partial configuration has the
enable flag set but is incomplete, Project Alpha pauses outbound delivery while
continuing to record authoritative events for later retry. It shows the missing
non-secret setting categories without altering stored values, access
administration, or API-key pull sync. A full snapshot remains the reconciliation
authority after any historical capture gap.

Use a dedicated Project Alpha API key with only the stable `ops.sync.read` scope. The UI describes this as external operations synchronization, but the scope identifier remains stable for compatibility.

Secrets are encrypted with Project Alpha's persisted application encryption key. Passwords, pay rates, financial details, API secrets, private tokens, and integration secrets are never included in the operational projection.

## Delivery contract

Project Alpha writes signed, idempotent change events for Business Units, Projects, Team membership, Operations, Operation assignments, Tasks, Task assignments, and entitlements. Events include an event ID, source timestamp, schema version, configured application key, and HMAC signature. The receiver ignores duplicates and out-of-order changes.

The minute-scheduled outbox sender delivers queued events with the configured
Cloudflare Access service-token headers and Project Alpha event headers. The HMAC
signature is SHA-256 over `timestamp + "." + raw_request_body`. Failed deliveries
remain in the outbox for the existing retry schedule; a successful retry keeps the
same event identity so receiver-side idempotency remains effective.

The external application also performs a daily reconciliation using:

```text
GET /api/v1/ops/snapshot?page=1&limit=500
```

Follow `next_page` while `has_more` is true. The snapshot includes Project Managers, Business Unit-aware Projects, and the multi-worker `task_assignments` collection. It is the recovery authority if an incremental event is delayed or missed.

The integration status card reports queued deliveries, retry errors, client
roots, eligible contacts, and records requiring review. After deployment or a
configuration change, use **Reconcile client portal**. It is bounded to 1,000
roots per run and is safe to repeat.

The portal receiver origin is derived from the saved signed-event URL (or the
server-only `EXTERNAL_OPS_CLIENT_PORTAL_BASE_URL` override) and always uses
`/api/internal/project-alpha/portal-v2`. It reuses the connection's Access
service token. Projection signing remains a separate capability credential: an
existing encrypted profile credential is retained. A first deployment must
provide a `portal` entry for the application key in
`PORTAL_INTEGRATION_HMAC_SECRETS_JSON`, with `current` and `keyId` (or
`currentKeyId`), and configure the receiver with that same key ID and secret.
The legacy server-only `EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_KEY_ID` and
`EXTERNAL_OPS_CLIENT_PORTAL_SIGNING_SECRET` variables remain accepted for
deployment compatibility. These values are intentionally not copied from the
business-event HMAC secret and are never exposed as form fields.

The daily snapshot is reconciliation and recovery, not a replacement for the
event path. Account email, display name, PA role, active state, and explicit-access
changes refresh or revoke the entitlement projection through the same outbox.

## Sync Contract v2 foundation

The provider-neutral v2 bootstrap foundation is implemented in parallel at `GET /api/v2/ops/snapshot` for API keys with `ops.sync.read`, but it returns 404 unless `APP_SYNC_CONTRACT_V2_ENABLED=true`. It does not change the v1 route or its event delivery. See [Sync Contract v2](../reference/sync-contract-v2.html) for its identity, cursor, resource-version, fixture, and production-gate rules. Keep the foundation disabled until all covered mutations use the documented atomic event contract and the remaining production gates pass.

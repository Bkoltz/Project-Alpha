# Generic portal v2 integration

Project Alpha remains the authoritative source for organizations, departments, clients, projects, portal-authority principals, entitlements, and the client-safe Service Library. This optional adapter is provider-neutral and disabled after migration. It contains no deployment domain, tenant key, or credential.

## Enablement order

1. Apply migrations `0066_generic_portal_v2_integration.sql` and `0067_portal_projection_delivery.sql`, then pass schema health checks.
2. Create a dedicated API key with exactly one capability: `portal.pricing.preview` or `portal.quote-draft.create`. A `full` or multi-scope key is rejected.
3. Create a generic profile in Settings → Custom integrations. Leave every capability disabled.
4. Configure distinct pricing/draft source identifiers and HTTPS receiver routes. Outbound HMAC and optional receiver-authorization values are encrypted with `APP_ENCRYPTION_KEY`; they are never displayed again or written to logs.
5. Verify the five payload fixtures plus the byte-pinned `portal-integration-wire-v1.json` transport fixture in `tests/fixtures` and the compatibility test.
6. Create a workspace (which links only the selected profile), verify the profile/workspace allowlist, then create a portal principal and explicit manager entitlement. Contacts and public links do not create authority.
7. Preflight receiver authentication and the exact application key while its inbox remains disabled. After a separately approved receiver window is open, enable only the required profile capabilities, the profile delivery switch, scoped authoritative hooks, and finally outbound delivery. Queue the portal and Service Library snapshots from Projection recovery, run delivery, and verify every page and activation before enabling consumer reads.

Feature gates are exact-string opt-ins: `APP_PORTAL_PRICING_PREVIEW_ENABLED=true` and `APP_PORTAL_DRAFT_QUOTES_ENABLED=true`. Migration-created application flags also default to `0` for portal v2, relations v3, catalog v2, pricing preview, and draft quotes.

## Server-only command contract

Routes:

- `POST /api/v2/integrations/{applicationKey}/pricing-hints`
- `POST /api/v2/integrations/{applicationKey}/draft-quotes`

Browser `Origin` requests and unauthenticated preflight requests are rejected. The canonical JSON bytes are hashed before parsing. Provider-neutral headers are:

- `X-Portal-Integration-Application-Key`
- `X-Portal-Integration-Timestamp` (strict UTC milliseconds)
- `X-Portal-Integration-Body-SHA256`
- `X-Portal-Integration-Scope` (pricing)
- `X-Portal-Integration-Signature`
- `Idempotency-Key` (draft creation)

The signature input is `timestamp + "\nPOST\n" + canonicalPath + "\n" + scopeOrIdempotencyKey + "\n" + lowercaseBodySHA256`. The HMAC algorithm is SHA-256. Configure secrets with `PORTAL_INTEGRATION_HMAC_SECRETS_JSON`, keyed by the operator-selected application key and `pricing`/`draft`. Rotate by disabling the capability, replacing the environment secret at both endpoints, verifying fixtures, then re-enabling.

Pricing is planning guidance only. Fixed `each`/`project` services use exact minor-unit arithmetic. Variable, hourly, base-overage, package, tax, discount, or answer-dependent pricing remains unavailable for automated guidance and is marked for staff review. Draft creation never sends, approves, invoices, charges, or emails. It snapshots authorization, request, work-area, attachment metadata, catalog versions, and answers into private quote metadata and opens only the normal staff editor at `/quotes/{publicId}/edit`.

## Projection and recovery

Portal snapshot pages are capped at 100 records, 100 pages, and 2,000 records. Service Library pages are capped at 50 items, 100 pages, and 500 total items. All pages in a generation share the same monotonic per-profile source sequence, unique generation ID, hash, and counts; `snapshot.activate` follows every page. Relations/lifecycles require schema v3 and an explicit profile flag. Project `completed_at` is set on the first completed transition, retained across repeated completed updates, and cleared only by a later reopen.

Source versions are deterministic hashes of client-visible content. Renames, reparenting, deactivation, lifecycle changes, and Service Library question changes therefore cannot reuse a prior source version. Complete snapshots are the recovery mechanism after a gap or interrupted generation. Receiver activation must be atomic and must not expose partial pages.

The database outbox stores exact payload bytes, destination, signing key ID, and ordering state transactionally. The bounded sender is installed by `src/cron/send_portal_projection_outbox.php` and runs every minute, but it is inert until `portal_outbound_delivery_enabled=1` and the selected profile's delivery switch is enabled. Existing already-ingested portal data remains usable while the sender or external provider is unavailable.

Every profile/workspace association is an explicit many-to-many allowlist row. There is no implicit, installation-wide, or “all profiles” association. Unlinked pairs fail closed for manual snapshots, manager appointment/offboarding, projection fanout, pricing, and both organization and standalone-client draft authorization. Operators can link, unlink, and recover a pair in Settings → Custom integrations; unlinking takes effect immediately without deleting the workspace.

Unlinking a workspace that has an activated snapshot transactionally queues a workspace tombstone for that profile before deactivating the allowlist row. Disabling an enabled profile or its portal projection similarly queues one tombstone for each of that profile's activated workspaces in the same transaction as the flag change. Other profiles linked to the same workspace receive no revocation. Revocation clears only that profile/workspace's active snapshot marker, so a later relink must be followed by a complete snapshot before incremental events resume. A workspace that was never activated has no downstream state and therefore needs no tombstone.

Queued revocations are durable control-plane records, not evidence of network delivery. When profile or projection authority is disabled, PA transactionally marks unclaimed normal rows as superseded before appending profile-scoped tombstones. The sender admits only revocation rows for a disabled profile; a still-active in-flight normal claim must settle or expire before its stream's tombstone proceeds, and an expired claim cannot resurrect a superseded row. The profile delivery switch and installation-wide outbound gate must remain on until the receiver acknowledges those tombstones. While any profile outbox row is undelivered, PA rejects application-key/route changes; signing-key rotation is also rejected while non-dead-lettered rows remain, including revocations. Secret bytes are immutable for a signing key ID: rotation requires a distinct new key ID and a new secret. Operators must verify receiver state before retiring the old route or secret.

Profile key/route changes and every projection outbox enqueue serialize on the same `portal_integration_profiles` row lock inside their transaction. This prevents a concurrent producer from inserting an old-contract delivery between the pending-outbox check and a contract rotation. Producers that wait behind a disable reload the locked profile and fail closed instead of publishing with stale flags or routes.

Ordinary organization, department, client/contact, project create/update/reparent/status/delete, onboarding-merge, and department-contact assignment paths call the scoped mutation reconciler inside the authoritative database transaction. Service Library edits and linked Work Activity activation/deletion queue a complete bounded catalog generation in that same transaction. Reparent and delete paths lock the authoritative client/project row before reading the old scope, then reconcile that locked old scope with the actual post-mutation scope; multi-row paths acquire locks in numeric ID order. The reconciler operates only on those organization/standalone-client roots, persists relation upserts/tombstones, and fans out only through active profile/workspace allowlist rows. A projection fault rolls back to its savepoint, records only a short diagnostic hash, and allows the core PA mutation to commit; queue a complete snapshot to reconcile that explicit workspace afterward. No global reconciliation or implicit profile fanout is performed.

## Signed projection delivery

Portal and catalog deliveries use the exact stored JSON bytes. Provider-neutral headers are:

- `X-Portal-Integration-Application-Key`
- `X-Portal-Integration-Timestamp`
- `X-Portal-Integration-Body-SHA256`
- `X-Portal-Integration-Key-Id`
- `X-Portal-Integration-Delivery-Id`
- `X-Portal-Integration-Signature`

The outbound signature input is `timestamp + "\nPOST\n" + path + "\n" + keyId + "\n" + deliveryId + "\n" + exactBody`, signed with HMAC-SHA256. The receiver must validate the body digest, exact path, delivery ID, timestamp window, application/key pair, and signature before parsing JSON. It must persist the delivery ID/body fingerprint before applying a generation so timeout retries are idempotent. During rotation, PA retains one encrypted previous secret for the configured overlap window; pending rows keep their original key ID.

Destinations must be HTTPS URLs without embedded credentials, query strings, or fragments. The sender resolves the host for each attempt, accepts only public routable addresses, rejects the entire attempt if any DNS answer is unsafe, and rejects IPv4-mapped, compatible, NAT64, 6to4, Teredo, and ISATAP encodings that could hide a private or reserved IPv4 destination. It pins the selected address into cURL, verifies TLS, rejects redirects, caps the response body, and never stores routes, payloads, response bodies, auth headers, or exception messages in delivery errors. Optional generic receiver-auth headers are encrypted and restricted to four non-reserved values.

Success is any 2xx. Redirects, network failures, 408, 409, 425, 429, and 5xx responses retry with jittered exponential backoff from 30 seconds to one hour. Other 4xx responses and the configured attempt ceiling dead-letter the row. Delivery is ordered per profile/workspace/route; a retryable earlier row blocks later rows in that stream, while a dead-letter requires explicit operator investigation and complete-snapshot recovery. Claims expire after five minutes so a crashed worker can resume safely.

Manual run:

```sh
php /var/www/src/cron/send_portal_projection_outbox.php
```

Enable in Settings → Custom integrations only after the receiver inbox and Access/service-token path have passed a separately approved preflight. A disabled inbox commonly returns 404 and an Access mismatch commonly returns 403; both are intentionally non-retryable and will dead-letter the row. During the approved window: open the receiver inbox, save HTTPS routes and a 32+ character signing secret, enable profile delivery, enable scoped authoritative hooks, enable outbound delivery, queue both complete snapshots, run delivery, and verify activation. To disable portal authority, save the profile with portal projection disabled while delivery and the receiver remain open, drain and verify its queued revocations, then turn off profile delivery/global outbound and finally close the receiver inbox. Catalog shutdown does not remove the receiver's last known-good published generation.

## Abuse controls and retention

Pricing permits 30 requests/minute and draft creation 10 requests/minute per profile, dedicated key, capability, and source-IP hash. Pricing signatures are one-use. Draft retries with the same signature/body/idempotency key are permitted during the five-minute signing window but remain inside the 10/minute abuse budget; a changed body or key is rejected. A newly signed timeout retry with the same key and body is handled by the quote command table's atomic payload fingerprint and returns the original receipt without duplicating the quote.

`prune_portal_integration_security.php` runs every minute. It retains rate buckets for 48 hours and signed-request receipts for 24 hours, deleting oldest indexed rows first in batches of at most 5,000. Each invocation is bounded to 100,000 rows, 40 passes, or 2.5 seconds; its per-minute deletion ceiling therefore exceeds the admitted per-key request rates while remaining operationally bounded. Alert on sustained capped runs, which indicate aggregate abuse or an undersized maintenance allocation. Logs contain only request IDs, exception classes, counts, and bounded error codes—never payloads, routes with embedded credentials, HMAC material, tokens, client secrets, full paths, or upstream bodies.

## Operational checks

Monitor:

- undelivered outbox rows by oldest `created_at` and highest `attempts`;
- generations with pages but no queued activation;
- exhausted deliveries and sanitized `last_error_code` counts;
- receipt/rate-table row counts, per-minute prune results, and capped-run state;
- pricing unavailable reasons and draft idempotency conflicts;
- workspace/profile rows whose feature flags disagree.

Recovery is: disable the affected projection capability without changing its route/key contract, leave outbound delivery enabled long enough to drain revocations, investigate/dead-letter only with an audit trail, correct configuration, relink if needed, queue a complete snapshot in Custom integrations, verify page/activation ordering, then re-enable. Never delete pending outbox rows to recover a gap. Disabling this optional integration does not alter core PA records, existing quotes, public links, or staff access.

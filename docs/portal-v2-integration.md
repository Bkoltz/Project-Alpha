# Generic portal v2 integration

Project Alpha remains the authoritative source for organizations, departments, clients, projects, portal-authority principals, entitlements, and the client-safe Service Library. This optional adapter is provider-neutral and disabled after migration. It contains no deployment domain, tenant key, or credential.

## Enablement order

1. Apply migration `0066_generic_portal_v2_integration.sql` and pass schema health checks.
2. Create a dedicated API key with exactly one capability: `portal.pricing.preview` or `portal.quote-draft.create`. A `full` or multi-scope key is rejected.
3. Create a generic profile in Settings → Custom integrations. Leave every capability disabled.
4. Configure distinct pricing/draft source identifiers and HTTPS receiver routes. Secrets remain environment-owned; they are never stored in the profile.
5. Verify the five byte-exact fixtures in `tests/fixtures` and the compatibility test.
6. Create a workspace (which links only the selected profile), verify the profile/workspace allowlist, then create a portal principal and explicit manager entitlement. Contacts and public links do not create authority.
7. Enable only the required profile capabilities, then the matching process environment gates.

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

Portal snapshot pages are capped at 100 records, 100 pages, and 2,000 records. All pages in a generation share the same source sequence/hash/counts; `snapshot.activate` follows every page. Relations/lifecycles require schema v3 and an explicit profile flag. Project `completed_at` is set on the first completed transition, retained across repeated completed updates, and cleared only by a later reopen.

Source versions are deterministic hashes of client-visible content. Renames, reparenting, deactivation, lifecycle changes, and Service Library question changes therefore cannot reuse a prior source version. Complete snapshots are the recovery mechanism after a gap or interrupted generation. Receiver activation must be atomic and must not expose partial pages.

The database outbox stores exact payload bytes and ordering state transactionally. Automatic outbound delivery of principals and entitlements must not be installed until the operator explicitly authorizes that egress and supplies a reviewed, signed delivery runner. Existing already-ingested portal data remains usable while the sender or external provider is unavailable.

Every profile/workspace association is an explicit many-to-many allowlist row. There is no implicit, installation-wide, or “all profiles” association. Unlinked pairs fail closed for manual snapshots, manager appointment/offboarding, projection fanout, pricing, and both organization and standalone-client draft authorization. Operators can link, unlink, and recover a pair in Settings → Custom integrations; unlinking takes effect immediately without deleting the workspace.

Unlinking a workspace that has an activated snapshot transactionally queues a workspace tombstone for that profile before deactivating the allowlist row. Disabling an enabled profile or its portal projection similarly queues one tombstone for each of that profile's activated workspaces in the same transaction as the flag change. Other profiles linked to the same workspace receive no revocation. Revocation clears only that profile/workspace's active snapshot marker, so a later relink must be followed by a complete snapshot before incremental events resume. A workspace that was never activated has no downstream state and therefore needs no tombstone.

Queued revocations are durable control-plane records, not evidence of network delivery. The separately approval-gated sender must drain already-queued revocations even after a profile is disabled, then stop normal publication for that profile. While any profile outbox row is undelivered, PA rejects changes to that profile's application key or receiver routes so a revocation cannot silently switch signing or destination contracts. Operators must verify the tombstone receipt at the receiver before rotating or retiring the old route/secret. Until that sender is explicitly approved and installed, profiles remain default-off and disabling them does not by itself prove a remote receiver has deleted stale access.

Profile key/route changes and every projection outbox enqueue serialize on the same `portal_integration_profiles` row lock inside their transaction. This prevents a concurrent producer from inserting an old-contract delivery between the pending-outbox check and a contract rotation. Producers that wait behind a disable reload the locked profile and fail closed instead of publishing with stale flags or routes.

Ordinary core rename/reparent/deactivate mutations are **not automatically wired to portal projection in this release**. The mutation helper and manual complete-snapshot recovery exist, and the already-transactional Service Library/catalog path is wired, but organization, department, client, contact-assignment, and general project mutation hooks remain deliberately uninstalled pending an explicit invasive-mutation review. Keep projection capabilities disabled unless the deployment accepts manual recovery for those changes. Do not describe the adapter as live-synchronized until those scoped, transaction-safe hooks are reviewed and installed.

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

Recovery is: disable the affected capability, preserve and deliver queued revocations under the existing route/key contract, correct configuration, relink if needed, queue a complete snapshot in Custom integrations, verify page/activation ordering, then re-enable. Never delete pending outbox rows to recover a gap. Disabling this optional integration does not alter core PA records, existing quotes, public links, or staff access.

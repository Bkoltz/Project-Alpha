# Internal Notification Relay

Project Alpha includes a provider-neutral, disabled-by-default relay for narrowly scoped transactional email requests from a companion service. The caller selects only an operator-configured action, template, and recipient alias. Project Alpha resolves the fixed template and address, escapes variables, durably queues the request, and sends it through Project Alpha's existing configured mail provider.

The relay never returns or duplicates SMTP usernames, passwords, app passwords, or provider credentials. It does not accept caller-supplied subjects, bodies, sender fields, arbitrary email addresses, attachments, CC/BCC, or reply-to headers.

## Security Boundary

There is no relay route in `public/index.php` and no relay file under the public document root. The HTTP entrypoint is `src/internal/notification_relay_endpoint.php`; it must be mapped explicitly on a separate private listener or internal FastCGI location that the public listener cannot reach. The repository does not add or publish that listener. While disabled, this entrypoint returns `404` before database access or API-key authentication, and it never starts a PHP session.

The relay additionally requires:

- a dedicated API key with the explicit `notifications.enqueue` scope;
- a non-empty exact-IP allowlist on that key;
- the relay feature flag enabled in both the web and cron containers;
- a valid server-owned policy file visible at the same path in both containers.

Legacy `full` API keys do **not** inherit `notifications.enqueue`. Source-IP checks are defense in depth; configure trusted proxies correctly and ensure the ingress removes untrusted forwarding headers.

## Request Contract

```json
{
  "action": "service.event",
  "template": "event-summary",
  "recipient": "operations",
  "variables": {
    "reference": "example-123",
    "summary": "A bounded transactional status message"
  },
  "idempotency_key": "event:example-123:v1"
}
```

Send an HTTP `POST` to the operator-defined private URL with `Content-Type: application/json` and either `Authorization: Bearer <service-key>` or `X-API-Key: <service-key>`. The body is capped at 32 KiB. Unknown fields, actions, templates, recipient aliases, and variables fail closed.

A new request returns `202` with a queue ID. Repeating the same credential/idempotency key and canonical payload returns the existing queue record with `200`. Reusing that key for different content returns `409`.

## Policy Configuration

Copy [the example policy](../config/notification-relay.example.json) to the persistent configuration volume as `/var/www/config/notification-relay.json`, then replace every example alias, address, action, template, and limit with reviewed deployment values. The policy is not a secret, but it contains recipient addresses and should be readable only by appropriate operators and the web/cron processes.

The mapping is deliberately server-owned:

- `recipients` maps a request-safe alias to exactly one validated email address.
- `templates` defines a fixed subject plus exactly one HTML or plain-text body and declares every allowed variable.
- `actions` maps each action to the only templates and recipient aliases it may use.
- `limits` controls atomic per-key/per-minute and per-key-recipient/per-hour limits, active backlog, worker lease/batch/retry behavior, and data retention.

HTML template variables are escaped. Subject control characters are removed. The resolved recipient and template fingerprint are included in the request-integrity hash. Policy changes are re-evaluated immediately before delivery, so changing or removing an action/template/recipient dead-letters an already queued request rather than silently changing its destination or content.

## Service Credential Prerequisites

These values are created or supplied by the operator; no secret values belong in source control or documentation.

1. In **Settings > API Keys**, create a new key used only by the companion service.
2. Select only `Internal notification relay (notifications.enqueue)`. Do not select `Full API access`.
3. Set the key's exact allowed source IP address or addresses. CIDR syntax is not supported by the current API-key field.
4. Transfer the one-time displayed key to the companion service's secret store over an approved channel.
5. Keep TLS enabled, rotate the key on exposure or ownership change, and revoke it when the integration is retired.

The service credential is a Project Alpha API key. It is not the SMTP/app-password credential, and the companion service must never receive the latter.

## Application and Worker Prerequisites

1. Configure and test Project Alpha's existing mail provider through the supported application settings. The relay refuses PHP `mail()` fallback and uses one configured PHPMailer provider attempt per worker attempt.
2. Ensure web and cron share the same persistent `/var/www/config` volume and application encryption key so the cron process can decrypt the existing provider credential.
3. Apply the normal database migration before starting the new web/cron images. Migration `0026` adds the queue, rate, active-count, and append-only event tables.
4. Mount the reviewed policy at the configured path in both containers.
5. Add a separately bound private listener that maps only its relay location to `/var/www/src/internal/notification_relay_endpoint.php`. Restrict it to the companion network and do not add the location to Project Alpha's public virtual host. This is an operator prerequisite, not a committed production listener.
6. Set both of these in the **web and cron** environments:

   ```text
   NOTIFICATION_RELAY_ENABLED=true
   NOTIFICATION_RELAY_CONFIG_PATH=/var/www/config/notification-relay.json
   ```

7. Restart/recreate both services and verify `process_notification_relay` in `cron_job_runs` plus the redacted cron logs.

The committed Compose files do not enable the relay, add a public listener, or contain deployment credentials. Enabling it is a separate operator-controlled production change.

## Delivery, Retry, and Audit Behavior

The cron entry runs every minute, but its disabled path exits before connecting to the database or writing logs. When enabled, the worker transactionally claims due rows with a lease and reclaims expired leases. Its provider connection and command timeout stays at least ten seconds below that lease so a normal in-flight attempt is not reclaimed. Provider failures use configured backoff with jitter and end in `failed` after the configured attempt cap. Atomic rate buckets control each credential and each recipient within that credential; an active-count row enforces the per-key backlog cap.

Accepted, duplicate, claimed, retry, sent, failed, and lease-expired transitions are recorded in `notification_relay_events`, including a durable numeric queue reference. An expired worker lease is dead-lettered instead of reclaimed after the configured attempt cap. Terminal queue rows retain the recipient, variables, idempotency hash, source IP, and source user agent for `payload_retention_days` after their last update; active rows remain until delivery or failure, and pseudonymized events remain for `event_retention_days`. The general `system_audit` also receives best-effort pseudonymized entries containing hashes and aliases, never the authorization token, message body, template variables, provider response, or provider credentials.

Cleanup is deliberately disabled with the relay so the default-off cron path has no database side effects. Before decommissioning, leave the worker enabled until the queue has no `pending`, `retry`, or `processing` rows, then retain it through the configured cleanup window or delete the remaining relay queue data through an approved database-retention procedure. Disabling the relay is not itself a purge operation.

SMTP delivery is at-least-once. A process can fail after the provider accepts a message but before Project Alpha records `sent`, so a retry can rarely produce a duplicate. A stable Message-ID is reused for attempts to help downstream systems deduplicate, but it cannot guarantee exactly-once delivery. Caller idempotency prevents duplicate queue creation; it cannot eliminate this SMTP ambiguity.

## Verification

Run the focused tests and migration checks before any environment enablement:

```bash
composer test -- tests/Notifications
php src/migrations/run_migrations.php --validate-files
docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm migrate \
  php /var/www/src/migrations/run_migrations.php --dry-run --verbose
```

Use a non-production environment and a controlled recipient alias for the first end-to-end delivery. Never test with production recipient data or expose raw logs in an issue.

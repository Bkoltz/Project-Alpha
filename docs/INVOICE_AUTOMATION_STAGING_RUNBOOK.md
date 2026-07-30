# Invoice Automation Staging Runbook

This runbook promotes the durable invoice-delivery, reminder, PDF, public-link, and due-date changes introduced by migrations `0058` and `0059` into an isolated staging deployment. It assumes session migration `0057` is already present and does not authorize a production deployment or emailing real clients.

## Safety boundaries

- Use the staging database, staging volumes, staging hostname, and an email sink or provider sandbox. Do not reuse production credentials or recipients.
- Keep the staging `cron` service stopped until the migration review gates below are complete.
- Back up MySQL and the shared configuration volume, including its encryption key, before applying migrations.
- Use immutable web and cron images built from the same source commit: `sha-<commit>` and `cron-sha-<commit>`.
- Do not deploy a pull-request branch from mutable `:dev` and `:cron` tags without first proving which commit each tag contains.
- Never run a broad historical email backfill. The incident procedure below accepts one explicitly approved invoice ID at a time.

## 1. Preflight

Set both staging services to immutable images from one source commit:

```text
web:  ghcr.io/ledgetoptechnologies/project-alpha:sha-<source-commit>
cron: ghcr.io/ledgetoptechnologies/project-alpha:cron-sha-<source-commit>
```

These tags are published only for commits pushed through the repository's `dev`, `main`, or release-tag image workflow. A draft pull request alone does not publish them.

Confirm the isolated Compose definition:

```bash
docker compose -f docker-compose.staging.yml config
docker compose -f docker-compose.staging.yml ps
```

Verify that staging has its own database and the `pa_staging_config`, `pa_staging_uploads`, `pa_staging_backups`, and `pa_staging_db_data` volumes. Replace every example password with a staging-only secret.

Record these non-secret effective settings:

| Area | Required check |
|---|---|
| System | `app_host` is the canonical staging HTTPS origin, with no path, credentials, or production hostname |
| System | `timezone` is a valid IANA timezone such as `America/Chicago` |
| System | `public_links_in_email` matches the intended staging test |
| Sender | `from_email` or `smtp_from_email` is a staging-safe sender |
| Outgoing email | Exactly one active SMTP or Gmail connection, safe sender identity, provider status, and encrypted credentials present |
| Workflow | `cron_enabled`, `invoice_auto_email_on_generate`, `invoice_auto_send_due_7days`, and `invoice_auto_send_overdue_weekly` |
| Projects | `project_invoice_auto_email` for test projects |
| Project clients | `send_project_invoices` for each test recipient |

Do not print or copy `smtp_password_enc` or `email_provider_connections.credentials_enc`. Web and cron must mount the same staging configuration volume and use the same `APP_ENCRYPTION_KEY` so both can decrypt the active provider credentials. Legacy SMTP settings may be imported into the provider tables on first use.

```sql
SELECT config_key,
       CASE WHEN config_key = 'smtp_password_enc'
            THEN IF(config_value = '', 'missing', 'present')
            ELSE config_value END AS configured_value
FROM app_config
WHERE organization_id = 0
  AND config_key IN (
    'app_host', 'timezone', 'public_links_in_email', 'cron_enabled',
    'invoice_auto_email_on_generate', 'invoice_auto_send_due_7days',
    'invoice_auto_send_overdue_weekly', 'smtp_host', 'smtp_port',
    'smtp_secure', 'smtp_username', 'smtp_password_enc',
    'smtp_from_email', 'smtp_from_name', 'from_email', 'from_name'
  )
ORDER BY config_key;
```

Review active-provider metadata without selecting encrypted credentials:

```sql
SELECT c.provider, c.display_name, c.sender_email, c.sender_name,
       c.status, c.token_expires_at, c.last_verified_at, c.last_error
FROM email_provider_state s
LEFT JOIN email_provider_connections c ON c.id = s.active_connection_id
WHERE s.id = 1;
```

### Legacy delivery review required by 0058

Migration `0058` recovers legacy notification claims with no `sent_at` by marking them retryable. Count and identify those rows before migration:

```sql
SELECT 'invoice' AS queue_name, COUNT(*) AS rows_without_sent_at
FROM invoice_notifications WHERE sent_at IS NULL
UNION ALL
SELECT 'project_invoice', COUNT(*)
FROM project_invoice_notifications WHERE sent_at IS NULL;
```

Export the matching row IDs, invoice IDs, recipients, types, creation times, and stored subjects for an incident reviewer. Do not include message bodies or client data in a public issue. Decide which may retry and which must be suppressed after migration. Keep cron stopped until that decision is applied.

### Due-date provenance preview required by 0059

Migration `0059` classifies an existing due date as term-derived only when it exactly equals the document date plus applicable net terms. Preview and spot-check the affected invoices:

```sql
SET @default_net_terms_days := COALESCE((
  SELECT CAST(config_value AS UNSIGNED)
  FROM app_config
  WHERE organization_id = 0 AND config_key = 'net_terms_days'
  LIMIT 1
), 30);

SELECT i.id, i.doc_number, i.document_date, i.due_date,
       COALESCE(p.invoice_net_terms_days, @default_net_terms_days) AS term_days
FROM invoices i
LEFT JOIN projects p ON p.id = i.project_id
WHERE i.due_date IS NOT NULL
  AND DATE(i.due_date) = DATE_ADD(
    DATE(i.document_date),
    INTERVAL COALESCE(p.invoice_net_terms_days, @default_net_terms_days) DAY
  )
ORDER BY i.id;
```

If an intentionally manual due date happens to equal that formula, resolve its provenance before deployment or restore it to `due_date_source='manual'` immediately after migration and before users update its document date.

## 2. Back up and validate migrations

Stop application work while leaving the database available:

```bash
docker compose -f docker-compose.staging.yml stop cron web
```

Create and verify an operator-level staging backup in addition to the migrator's required compressed backup. Confirm that the backup and configuration volume can be restored into a separate environment.

Pull the selected immutable images, validate the migration package, and dry-run it:

```bash
docker compose -f docker-compose.staging.yml pull
docker compose -f docker-compose.staging.yml run --rm migrate \
  php /var/www/src/migrations/run_migrations.php --validate-files
docker compose -f docker-compose.staging.yml run --rm migrate \
  php /var/www/src/migrations/run_migrations.php --dry-run --verbose
```

The sequence must contain 59 migrations and report session migration `0057` before invoice migrations `0058` and `0059`. Resolve any checksum, sequence, backup, or schema error instead of bypassing the migrator.

Apply through the normal one-shot service:

```bash
docker compose -f docker-compose.staging.yml run --rm migrate
```

Do not apply the SQL files manually. MySQL DDL may auto-commit, so a partially failed migration requires the documented restore/fix-forward procedure.

## 3. Validate schema and recovered rows

```sql
SELECT version, filename, applied_at
FROM schema_migrations
WHERE version IN (26, 27)
ORDER BY version;

SELECT table_name, column_name
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND (
    (table_name IN ('invoice_notifications', 'project_invoice_notifications')
      AND column_name IN (
        'delivery_key', 'recipient_key', 'delivery_status', 'attempt_count',
        'next_attempt_at', 'last_attempt_at', 'claimed_at', 'last_error',
        'updated_at'
      ))
    OR (table_name = 'invoices'
      AND column_name IN ('payment_terms_days', 'due_date_source'))
  )
ORDER BY table_name, ordinal_position;

SELECT table_name, index_name,
       GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND index_name IN (
    'uq_invoice_notification_delivery', 'idx_inv_notif_delivery_due',
    'uq_project_invoice_notification_delivery', 'idx_project_invoice_notif_due'
  )
GROUP BY table_name, index_name
ORDER BY table_name, index_name;
```

Review every row recovered by `0058` before cron starts:

```sql
SELECT id, invoice_id, notification_type, delivery_key, email_to,
       delivery_status, attempt_count, next_attempt_at, last_error
FROM invoice_notifications
WHERE last_error = 'Recovered legacy delivery claim without a sent timestamp.'
ORDER BY id;

SELECT id, project_invoice_id, notification_type, delivery_key, email_to,
       delivery_status, attempt_count, next_attempt_at, last_error
FROM project_invoice_notifications
WHERE last_error = 'Recovered legacy delivery row without a sent timestamp.'
ORDER BY id;
```

Suppress each unapproved retry by its exact primary key while cron remains stopped:

```sql
UPDATE invoice_notifications
SET delivery_status = 'suppressed',
    next_attempt_at = NULL,
    last_error = 'Suppressed during migration review; retry not approved.'
WHERE id = <reviewed-notification-id>
  AND delivery_status = 'retry';
```

Use the equivalent statement on `project_invoice_notifications`. Do not use an unreviewed range, wildcard, or `NOT IN` list.

## 4. Start web, configure staging, then start cron

```bash
docker compose -f docker-compose.staging.yml up -d web
docker compose -f docker-compose.staging.yml ps
```

In the staging UI:

1. Save the canonical staging `app_host` and intended `public_links_in_email` value.
2. Save the IANA timezone.
3. Select exactly one active outgoing-email provider. Use an SMTP sink or provider sandbox and send its test message only to a controlled staging inbox.
4. Review the global generation and reminder toggles.
5. Review the test project's automatic email setting and each project client's delivery preference.

Changing the application timezone requires recreating or restarting cron so the daemon reloads `/etc/localtime`. Start it only after settings and recovered rows are approved:

```bash
docker compose -f docker-compose.staging.yml up -d cron
docker compose -f docker-compose.staging.yml exec cron \
  cat /etc/cron.d/project-alpha
```

The installed schedule uses the configured Project Alpha timezone:

- recurring generation: daily at 02:00
- reminder scheduling and delivery: daily at 08:00

The `cron_schedule` and `cron_custom` UI values do not rewrite the image's installed crontab.

## 5. Staging acceptance

Use synthetic clients and an email sink. Do not use real client addresses. Verify:

1. An eligible long-term contract generates one finalized invoice and one `on_generate/generated` queue row.
2. Re-running the generator creates no duplicate invoice or queue row.
3. A valid recipient gets one message with the staging URL, PDF, actual due date, human-readable terms, outstanding balance, and safe invoice reference.
4. Missing or invalid recipients create observable `suppressed` rows.
5. Disabled generation, reminder, project, and per-client settings suppress delivery.
6. A pre-due invoice gets one reminder in the seven-day window; an overdue invoice gets at most one per seven-day stage.
7. Paid, voided, cancelled, draft, non-direct, or zero-balance invoices stop.
8. A term-derived due date follows its changed document date; a manual due date stays fixed; stale reminder stages suppress themselves.
9. A forced PDF or SMTP failure records `retry`, `last_error`, and `next_attempt_at`; a later run succeeds once without leaking a failed public link.

Manual staging invocations:

```bash
docker compose -f docker-compose.staging.yml exec cron \
  php /var/www/src/cron/generate_recurring_invoices.php
docker compose -f docker-compose.staging.yml exec cron \
  php /var/www/src/cron/send_invoice_reminders.php
docker compose -f docker-compose.staging.yml exec cron \
  tail -n 200 /var/www/config/logs/cron/cron.log
```

Observe cron and queue outcomes:

```sql
SELECT job_name, status, last_run, completed_at, result, error_message
FROM cron_job_runs
WHERE job_name IN ('generate_recurring_invoices', 'send_invoice_reminders')
ORDER BY job_name;

SELECT delivery_status, notification_type, COUNT(*) AS row_count,
       MIN(next_attempt_at) AS next_attempt
FROM invoice_notifications
GROUP BY delivery_status, notification_type
ORDER BY delivery_status, notification_type;

SELECT delivery_status, notification_type, COUNT(*) AS row_count,
       MIN(next_attempt_at) AS next_attempt
FROM project_invoice_notifications
GROUP BY delivery_status, notification_type
ORDER BY delivery_status, notification_type;

SELECT message_key, document_type, document_id, recipient, status,
       sent_at, error_message, updated_at
FROM email_delivery_log
WHERE message_key LIKE 'invoice-notification:%'
   OR message_key LIKE 'project-invoice-notification:%'
ORDER BY id DESC
LIMIT 100;
```

Investigate any `processing` row older than ten minutes, unexpected `retry`, and every `last_error`. The worker reclaims stale processing rows and uses bounded exponential retry delays, but persistent failures require operator action.

## 6. Incident-specific recurring invoice backfill

Use this only for an invoice proven to have been generated by a recurring schedule while automatic generation email was enabled, but to have no durable `on_generate/generated` action. It queues delivery; it does not alter invoice amounts, dates, status, or payments.

### Minimum production evidence

Collect only:

- numeric invoice ID and redacted document number
- `invoice_type`, `finalization_source`, `collection_mode`, status, `finalized_at`, total, amount paid, due date, and current email validity
- effective auto-email, cron, public URL, timezone, and non-secret active-provider metadata at incident time if available
- matching notification rows and redacted cron run/log entries
- deployed web and cron image tags or digests

This distinguishes eligibility/opt-out/configuration from a missing enqueue or delivery failure without production credentials or message contents.

### Dry-run one approved invoice

```sql
SET @approved_invoice_id := <one-reviewed-invoice-id>;

SELECT i.id, i.doc_number, i.invoice_type, i.finalization_source,
       i.collection_mode, i.status, i.finalized_at, i.total, i.amount_paid,
       (i.total - i.amount_paid) AS outstanding_balance, i.due_date,
       c.email,
       COALESCE((
         SELECT config_value FROM app_config
         WHERE organization_id = 0
           AND config_key = 'invoice_auto_email_on_generate'
         LIMIT 1
       ), '1') AS auto_email_enabled
FROM invoices i
JOIN clients c ON c.id = i.client_id
WHERE i.id = @approved_invoice_id;

SELECT id, notification_type, delivery_key, email_to, delivery_status,
       attempt_count, next_attempt_at, sent_at, last_error
FROM invoice_notifications
WHERE invoice_id = @approved_invoice_id
ORDER BY id;
```

Proceed only when the invoice is `long_term`, came from `recurring_schedule`, is finalized, uses direct collection, has an eligible open status and positive balance, has a currently valid client email, and auto-email is enabled. Stop if any delivery row already represents the action; investigate it instead.

### Queue idempotently, held for review

The far-future `next_attempt_at` holds the row while it is reviewed:

```sql
START TRANSACTION;

INSERT IGNORE INTO invoice_notifications (
  invoice_id, notification_type, delivery_key, recipient_key,
  delivery_status, attempt_count, next_attempt_at, email_to
)
SELECT i.id, 'on_generate', 'generated',
       SHA2(LOWER(TRIM(c.email)), 256),
       'pending', 0, '2099-12-31 23:59:59', TRIM(c.email)
FROM invoices i
JOIN clients c ON c.id = i.client_id
WHERE i.id = @approved_invoice_id
  AND i.invoice_type = 'long_term'
  AND i.finalization_source = 'recurring_schedule'
  AND i.collection_mode = 'direct'
  AND i.status IN ('sent', 'unpaid', 'partial', 'overdue')
  AND i.finalized_at IS NOT NULL
  AND (i.total - i.amount_paid) > 0.005
  AND TRIM(c.email) <> ''
  AND COALESCE((
    SELECT config_value FROM app_config
    WHERE organization_id = 0
      AND config_key = 'invoice_auto_email_on_generate'
    LIMIT 1
  ), '1') = '1';

SELECT ROW_COUNT() AS rows_queued;

SELECT id, invoice_id, notification_type, delivery_key, email_to,
       delivery_status, next_attempt_at
FROM invoice_notifications
WHERE invoice_id = @approved_invoice_id
  AND notification_type = 'on_generate'
  AND delivery_key = 'generated';

COMMIT;
```

`rows_queued` must be `1` for a missing action or `0` when the idempotency key exists or eligibility failed. The SQL intentionally does not approximate PHP's full email validation; a reviewer must validate the current address before release, and the worker validates it again.

Release only the reviewed row:

```sql
UPDATE invoice_notifications
SET next_attempt_at = NOW()
WHERE invoice_id = @approved_invoice_id
  AND notification_type = 'on_generate'
  AND delivery_key = 'generated'
  AND delivery_status = 'pending'
  AND next_attempt_at = '2099-12-31 23:59:59';
```

Let normal cron deliver and monitor the exact row. Repeating the insert or scheduler run cannot duplicate the same invoice, stage, and recipient.

Before release, cancel safely by setting that exact row to `suppressed` and clearing `next_attempt_at`. After `sent_at` is set, email cannot be recalled; never edit a sent row to manufacture another attempt. Fix the underlying PDF, URL, configuration, or outgoing-provider cause for a `retry` row and leave it to normal retry processing.

## 7. Rollback and promotion decision

- If migration fails, keep web and cron stopped. Restore the required backup into an isolated database, diagnose, and fix forward.
- After successful migration, do not delete the new columns or indexes. They are additive and compatible with prior application defaults.
- To stop delivery without changing schema, stop cron and disable the relevant workflow toggles. Review pending/retry rows individually before restarting.
- Promote only when acceptance passes, both images come from one approved source commit, migration/recovery rows are reviewed, canonical URL and timezone are correct, the active outgoing provider uses the intended environment, and queue/cron observability is clean.

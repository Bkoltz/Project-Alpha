# General-Recipient Invoice Release Runbook

This runbook covers the isolated general-recipient invoice feature and migration `0060_general_recipient_invoices.sql`. It does not include or authorize the client-portal foundation.

## Release boundaries

- Build the web, cron, and database images from one immutable source commit.
- Use a staging-only database, Stripe test mode, synthetic client data, and an email sink.
- Do not push, merge, deploy, or send a real invoice until the automated suite, the manual acceptance flow, and the final security review pass on the same snapshot.
- Confirm the release diff does not contain `0060_client_portal_foundation.sql` or other portal files.

## Migration gates

Validate the 60-file sequence and dry-run through the normal migrator:

```bash
php /var/www/src/migrations/run_migrations.php --validate-files
php /var/www/src/migrations/run_migrations.php --dry-run --verbose
```

Test both paths:

1. Upgrade a staging database whose migration ledger ends at `0059`.
2. Initialize a fresh database from `database/baseline.sql`, then run all migrations.

Confirm the column and index:

```sql
SELECT column_name, column_default, is_nullable
FROM information_schema.columns
WHERE table_schema = DATABASE()
  AND table_name = 'invoices'
  AND column_name = 'recipient_presentation_mode';

SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) AS columns
FROM information_schema.statistics
WHERE table_schema = DATABASE()
  AND table_name = 'invoices'
  AND index_name = 'idx_invoices_recipient_presentation'
GROUP BY index_name;
```

The column must be non-null with default `named`; existing invoices must remain named.

## Automated gates

Run the complete PHPUnit suite against MySQL and confirm the database tests execute rather than skip. At minimum, retain explicit results for:

```bash
vendor/bin/phpunit \
  tests/Workflows/GeneralRecipientInvoiceTest.php \
  tests/Database/GeneralRecipientInvoiceDatabaseTest.php \
  tests/Payments/PaymentIntegrityTest.php \
  tests/Workflows/InvoiceAutomationTest.php \
  tests/Payments/OneTimePaymentPolicyTest.php
```

Also run PHP syntax checks, migration validation, `composer validate --strict --no-check-publish`, `composer audit --locked`, and the canonical Compose build/configuration checks.

## Staging acceptance

Create a synthetic internal accounting client whose name, organization, address, phone, and email are easy to recognize. Then verify:

1. Toggling general-recipient mode clears an already selected project, service location, tracked-time association, and mileage association.
2. Saving a draft does not make it public or payable.
3. **Finalize & Create Link** finalizes the invoice and returns one reusable manual link. Repeating the action returns the same active link.
4. The authenticated detail page identifies the invoice as general-recipient while retaining the internal client for bookkeeping.
5. Public HTML and PDF say **General Recipient** and contain none of the synthetic client's identifying fields or configured client/organization content links.
6. Automatic invoice email, reminders, and the separate payment-receipt email/page are not created for this invoice.
7. With Stripe test mode enabled, Checkout describes only the invoice and brand, charges the exact outstanding amount, and cannot be reused after full payment.
8. Duplicate Checkout and Payment Intent webhook deliveries create one successful payment and do not change the first `paid_at` value.
9. After payment, the original public HTML and PDF are non-payable receipts through `paid_at + 7 days`; the expiry is not extended by views or duplicate events.
10. After a refund and later repayment, the link uses exactly seven days from the new `paid_at`, not the first payment's deadline.
11. Authenticated finalize redirects and browser history do not contain the public bearer token; it is shown once from server-side session state.
12. At the seven-day boundary, both public HTML and PDF are unavailable.
13. A normal paid invoice still uses its existing redirected terminal link and ordinary receipt behavior.

Inspect the database after payment without copying tokens or client data into an issue:

```sql
SELECT i.status, i.paid_at, p.revoked, p.expire_when_paid,
       p.redirect, p.expires_at,
       TIMESTAMPDIFF(SECOND, i.paid_at, p.expires_at) AS receipt_window_seconds
FROM invoices i
JOIN public_links p
  ON p.document_type = 'invoice' AND p.document_id = i.id
WHERE i.id = <synthetic-general-invoice-id>;
```

Expected after full payment: `status='paid'`, `revoked=0`, `expire_when_paid=0`, `redirect IS NULL`, and `receipt_window_seconds=604800`.

## Promotion decision

Promote only the exact commit that passed the gates. Any code, migration, dependency, or documentation change after the security review creates a new snapshot and requires the affected tests and final review to run again. If Stripe test-mode acceptance or the exact seven-day boundary cannot be exercised, report that as a release blocker rather than treating static coverage as equivalent.

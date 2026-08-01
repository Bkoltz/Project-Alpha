# Recurring Invoices

Long-term contracts generate invoices from their stored billing schedule. The dedicated cron service runs the generator daily at 02:00 in the configured Project Alpha timezone.

## Prerequisites

A contract is eligible when it:

- Uses `contract_type='long_term'`
- Has status `active`
- Has a signed document path
- Has a non-empty `next_invoice_date` that is due
- Has valid billing interval and pricing configuration
- Runs while `cron_enabled` is enabled

## Activation

Uploading a signed long-term contract records the document but does not activate the contract in the authenticated workflow. The operator must select **Activate**. The current public signing route activates the contract when it accepts the client's signed upload.

Activation sets `next_invoice_date` from its existing value or the contract start date. If that date is already due, Project Alpha attempts to generate the first invoice immediately.

## Invoice Generation

The generator creates an invoice according to the contract's invoice-generation type and advances `next_invoice_date` by the configured interval.

Supported interval units are day, week, month, and year. Billing can use a set amount, itemized content, or a general write-up where configured.

The generator uses idempotency checks to avoid duplicate periods. It refetches overdue contracts for up to 36 passes in one run, allowing a recovered installation to catch up missed periods while limiting runaway execution.

## Notifications and Payment

When enabled:

- Newly generated invoices may be emailed with a public link.
- Due and overdue reminders run on the invoice reminder schedule.
- Stripe reconciliation runs every six hours to recover missed confirmations.

Recurring billing means automatic invoice generation and delivery. Every online payment remains a client-initiated, one-time Stripe Checkout payment. AutoPay is unavailable; see [AutoPay Beta Foundation](AUTOPAY_BETA.md).

## Manual Verification

```bash
docker compose exec cron php /var/www/src/cron/generate_recurring_invoices.php
docker compose exec cron tail -n 200 /var/www/config/logs/cron/cron.log
```

Also inspect `cron_job_runs`, the generated invoice, its billing period, and the contract's advanced `next_invoice_date`.

## Troubleshooting

- **No invoice generated:** confirm status, signature, due date, interval, pricing, and `cron_enabled`.
- **Duplicate concern:** compare the invoice billing period and contract ID before deleting anything.
- **Missed downtime periods:** run the generator once and review each catch-up invoice.
- **Email missing:** verify SMTP, public application URL, notification setting, and `invoice_notifications`.
- **Payment missing:** verify webhook delivery, then run Stripe reconciliation in test mode.

See [Document Workflow](DOCUMENT_WORKFLOW.md) for the complete long-term lifecycle.

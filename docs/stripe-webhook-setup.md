# Stripe Webhook Setup

Project Alpha uses Stripe webhooks to record online payments and reconcile invoice status.

## Endpoint

```text
https://YOUR_PROJECT_ALPHA_HOST/stripe-webhook
```

The endpoint must be reachable over HTTPS from Stripe. Do not use the legacy endpoint for new installations.

For older deployments that do not yet include the clean-path rewrite, use this equivalent fallback endpoint:

```text
https://YOUR_PROJECT_ALPHA_HOST/?page=stripe-webhook
```

## Events

Configure at least:

- `checkout.session.completed`
- `payment_intent.succeeded`

Useful additional events:

- `payment_intent.payment_failed`
- `refund.created`
- `refund.updated`
- `refund.failed`
- `charge.refunded`
- `charge.dispute.created`
- `charge.dispute.updated`
- `charge.dispute.closed`
- `charge.dispute.funds_withdrawn`
- `charge.dispute.funds_reinstated`

Only behavior implemented by the deployed version will be applied; unhandled events are logged or ignored.

## Configuration

1. In Stripe, create an endpoint with the production or test URL.
2. Select the required events.
3. Copy the endpoint signing secret.
4. In Project Alpha, open **Settings > Billing**.
5. Enter the matching publishable key, secret key, and webhook secret.
6. Save and send a test event.

Use test keys and a test-mode endpoint while validating the installation.

## Reconciliation Metadata

Project Alpha includes invoice identifiers in Stripe metadata. Preserve these fields when changing payment creation:

```json
{
  "pa_invoice_id": "123",
  "invoice_id": "123",
  "doc_number": "1001"
}
```

`pa_invoice_id` is the primary reconciliation identifier.

Project statement payments use `pa_project_invoice_id` and `project_invoice_id`. The aggregate payment is allocated to its child invoices; the project statement itself is never counted as additional revenue.

## Successful Payment Behavior

The handler:

1. Verifies the Stripe signature when a webhook secret is configured.
2. Records or reconciles the payment idempotently.
3. Recalculates the successful amount paid, net of recorded refunds.
4. Marks the invoice `partial` or `paid`.
5. Revokes invoice links after full payment.
6. May complete the linked contract.
7. Records refunds and disputes and updates cash-basis reporting.
8. Issues a branded Project Alpha receipt for ordinary invoice payments.

## Testing

Verify both supported paths:

- Public invoice payment through Stripe Checkout
- Public project-statement payment and child-invoice allocation
- Duplicate delivery and reconciliation of the same one-time Payment Intent

Send duplicate test events and confirm they do not create duplicate payment records.

## Troubleshooting

1. Confirm the endpoint URL and HTTPS certificate.
2. If `/stripe-webhook` returns `404`, either deploy an image with the clean-path rewrite or temporarily configure Stripe to use `/?page=stripe-webhook`.
3. Confirm test/live mode matches the configured keys.
4. Confirm the webhook signing secret belongs to this exact endpoint.
5. Review Stripe delivery attempts and response codes.
6. Review Project Alpha webhook and system logs.
7. Run reconciliation manually if a successful payment was missed:

   ```bash
   docker compose exec cron php /var/www/src/cron/stripe_reconciliation.php
   ```

The cron service also runs `stripe_reconciliation.php` every 6 hours, which is intentionally more frequent than a daily safety net.

Never include Stripe secrets or full event payloads containing customer data in a public issue.

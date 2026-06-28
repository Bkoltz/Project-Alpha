# Stripe Webhook Setup

Project Alpha uses Stripe webhooks to record online payments and reconcile invoice status.

## Endpoint

```text
https://YOUR_PROJECT_ALPHA_HOST/?page=stripe-webhook
```

The endpoint must be reachable over HTTPS from Stripe. Do not use the legacy endpoint for new installations.

## Events

Configure at least:

- `checkout.session.completed`
- `payment_intent.succeeded`

Useful additional events:

- `payment_intent.payment_failed`
- `charge.refunded`
- `charge.dispute.created`

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

## Successful Payment Behavior

The handler:

1. Verifies the Stripe signature when a webhook secret is configured.
2. Records or reconciles the payment idempotently.
3. Recalculates the successful amount paid.
4. Marks the invoice `partial` or `paid`.
5. Revokes invoice links after full payment.
6. May complete the linked contract.

## Testing

Verify both supported paths:

- Public invoice payment through Stripe Checkout
- Direct Payment Intent or auto-pay flow

Send duplicate test events and confirm they do not create duplicate payment records.

## Troubleshooting

1. Confirm the endpoint URL and HTTPS certificate.
2. Confirm test/live mode matches the configured keys.
3. Confirm the webhook signing secret belongs to this exact endpoint.
4. Review Stripe delivery attempts and response codes.
5. Review Project Alpha webhook and system logs.
6. Run reconciliation manually if a successful payment was missed:

   ```bash
   docker compose exec cron php /var/www/src/cron/stripe_reconciliation.php
   ```

Never include Stripe secrets or full event payloads containing customer data in a public issue.

# Webhook Routing

## Current Stripe Endpoints

| Route | Controller | Status |
|---|---|---|
| `/?page=stripe-webhook` | `src/controllers/webhook/stripe_webhooks.php` | Primary endpoint |
| `/?page=stripe-webhook-legacy` | `src/controllers/webhook/stripe_webhooks.php` | Compatibility alias to the primary endpoint |

New installations should configure only the primary endpoint.

Both routes are intentionally public and exempt from browser CSRF because Stripe cannot supply a session token. Authenticity instead depends on Stripe webhook signature validation. Configure the endpoint signing secret before production use.

When adding a provider or event:

1. Add an explicit route in `public/index.php`.
2. Verify provider authentication before trusting fields.
3. Add replay and duplicate-delivery protection.
4. Keep handlers idempotent.
5. Log identifiers and outcomes without secrets or unnecessary personal data.
6. Add provider test fixtures and duplicate-delivery tests.

See [Stripe Webhook Setup](stripe-webhook-setup.md).

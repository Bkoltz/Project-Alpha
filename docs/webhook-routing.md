# Webhook Routing Strategy

## Current Endpoints

### Stripe Webhooks
- **Primary:** `/?page=stripe-webhook` → `src/controllers/webhook/stripe_webhooks.php`
  - Future-proof router that handles all Stripe event types
  - Routes to individual handlers based on event type
  - Can easily be extended for new event types

- **Legacy:** `/?page=stripe-webhook-legacy` → `src/controllers/stripe/stripe_webhook.php`
  - Original monolithic webhook handler
  - Kept for backward compatibility
  - Can be removed after migration period

## Future Webhooks (Planned)

### PayPal Integration
```
/?page=webhook/paypal          → src/controllers/webhook/paypal_webhooks.php
/?page=webhook/paypal/success  → src/controllers/webhook/paypal_payment_succeeded.php
/?page=webhook/paypal/failed   → src/controllers/webhook/paypal_payment_failed.php
```

### Square Integration
```
/?page=webhook/square          → src/controllers/webhook/square_webhooks.php
```

### QuickBooks Integration
```
/?page=webhook/quickbooks      → src/controllers/webhook/quickbooks_webhooks.php
```

### Generic Webhooks
```
/?page=webhook/generic         → src/controllers/webhook/generic_webhooks.php
```

## Event Types Handled

### Stripe (Current)
- ✅ `checkout.session.completed`
- ✅ `checkout.session.expired`
- ✅ `payment_intent.succeeded`
- ✅ `payment_intent.payment_failed`
- ✅ `payment_intent.canceled`
- ✅ `payment_intent.requires_action`
- 🔄 `invoice.payment_succeeded` (future: subscriptions)
- 🔄 `invoice.payment_failed` (future: subscriptions)
- 🔄 `customer.subscription.created` (future: subscriptions)
- 🔄 `customer.subscription.updated` (future: subscriptions)
- 🔄 `customer.subscription.deleted` (future: subscriptions)
- 🔄 `charge.refunded` (future: refunds)
- 🔄 `charge.dispute.created` (future: disputes)

### PayPal (Future)
- 🔄 `PAYMENT.CAPTURE.COMPLETED`
- 🔄 `PAYMENT.CAPTURE.DENIED`
- 🔄 `BILLING.SUBSCRIPTION.ACTIVATED`
- 🔄 `BILLING.SUBSCRIPTION.CANCELLED`

## Adding a New Webhook Provider

1. Create router: `src/controllers/webhook/{provider}_webhooks.php`
2. Create handlers: `src/controllers/webhook/{provider}_{event}.php`
3. Add route in `public/index.php`
4. Add to `$publicPages` array (no auth required)
5. Add to `$skipCsrfFor` array (POST without CSRF)
6. Document in this file

## Security

- All webhook endpoints are public (no auth required)
- Each provider verifies their own signatures/tokens
- Failed events are logged but don't block other events
- Idempotency keys are checked to prevent duplicate processing

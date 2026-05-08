# Stripe Webhook Configuration Guide

## Overview
Project Alpha has a built-in webhook endpoint that handles Stripe payment events and automatically updates invoices.

## Endpoint Details

**URL:** `https://pa.ledgetopdroneservices.com/?page=stripe-webhook`

**Method:** POST

**Events Handled:**
- `checkout.session.completed` - Customer completes Stripe Checkout
- `payment_intent.succeeded` - Direct payment succeeds
- `payment_intent.payment_failed` - Payment fails (logged only)

## What Happens When Payment Succeeds

1. **Payment Recorded** in database with Stripe transaction ID
2. **Invoice Status Updated** to `paid` or `partial`
3. **Public Links Revoked** when invoice fully paid
4. **Contract Marked Complete** if linked to paid invoice
5. **Admin Notification** sent about payment

## Setup Instructions

### 1. Configure Stripe Dashboard

1. Go to [Stripe Dashboard](https://dashboard.stripe.com)
2. Navigate to **Developers → Webhooks**
3. Click **Add endpoint**
4. Enter URL: `https://pa.ledgetopdroneservices.com/?page=stripe-webhook`
5. Select events to listen for:
   - ✅ `checkout.session.completed`
   - ✅ `payment_intent.succeeded`
   - ✅ `payment_intent.payment_failed`
6. Click **Add endpoint**

### 2. Get Webhook Secret

1. In Stripe Dashboard, click on your new endpoint
2. Click **Reveal** next to **Signing secret**
3. Copy the secret (starts with `whsec_`)

### 3. Configure Project Alpha

1. Log into Project Alpha at https://pa.ledgetopdroneservices.com
2. Go to **Settings → Billing**
3. Paste webhook secret in **Webhook Secret** field
4. Save settings

## Testing

### Option A: Stripe CLI (Local Testing)
```bash
# Install Stripe CLI if not already installed
# https://stripe.com/docs/stripe-cli

# Login to Stripe
stripe login

# Forward events to local endpoint
stripe listen --forward-to localhost:1627/?page=stripe-webhook

# Trigger a test event
stripe trigger checkout.session.completed
```

### Option B: Stripe Dashboard
1. Go to your webhook endpoint in Stripe Dashboard
2. Click **Send test event**
3. Select event type: `checkout.session.completed`
4. Click **Send test event**
5. Check Project Alpha logs for success message

## Important Notes

### Invoice Must Exist
The webhook handler verifies the invoice exists before recording payment. If the invoice_id in the Stripe metadata doesn't exist in the database, the payment is silently skipped (returns 200 OK to Stripe so they don't retry).

### Metadata Requirements
When creating a Stripe Checkout Session or Payment Intent, include the invoice_id in metadata:
```php
$session = $stripe->createCheckoutSession(
    $amount,
    'usd',
    $description,
    $successUrl,
    $cancelUrl,
    [
        'invoice_id' => (string)$invoiceId,  // Required for webhook matching
        'pa_invoice_id' => (string)$invoiceId  // Alternative key
    ]
);
```

### Webhook Signature Verification
If webhook secret is configured in Project Alpha settings, Stripe signatures are verified. If not configured, events are processed without verification (not recommended for production).

## Troubleshooting

### "No payload" error
- Check that POST body is being sent
- Ensure Content-Type is `application/json`

### "Invalid event payload" error
- Stripe event may be malformed
- Check Stripe dashboard for event details

### "Stripe not configured" error
- Stripe API keys not set in Project Alpha settings
- Go to Settings → Billing and add Stripe keys

### "Invoice not found" in logs
- Invoice ID in Stripe metadata doesn't exist in database
- Verify invoice was created before payment

## Security

- Webhook endpoint is **public** (no auth required)
- Stripe signature is verified using webhook secret
- If secret not configured, events are processed without verification (not recommended for production)
- Failed events are logged but don't block other events

## Files Involved

- `src/controllers/webhook/stripe_webhooks.php` - Main webhook router
- `src/controllers/webhook/stripe_checkout_completed.php` - Checkout handler
- `src/controllers/webhook/stripe_payment_succeeded.php` - Payment success handler
- `src/controllers/webhook/stripe_payment_failed.php` - Payment failure handler
- `src/controllers/stripe/stripe_checkout.php` - Checkout session creation
- `src/controllers/stripe/stripe_success.php` - Success page
- `src/services/StripeService.php` - Stripe API integration

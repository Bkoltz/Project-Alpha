# Stripe Test Results

**Date tested:** 2026-06-16  
**Stripe mode:** Test  
**Test keys used:** `pk_test_...` / `sk_test_...`

## Tests Performed

### 1. StripeService Instantiation

```bash
docker compose exec web php -r "
require '/var/www/vendor/autoload.php';
require '/var/www/src/config/app.php';
require '/var/www/src/services/StripeService.php';
\$s = StripeService::fromAppConfig(\$appConfig);
echo 'instantiated: ' . (\$s ? 'PASS' : 'FAIL') . PHP_EOL;
echo 'has secret: ' . (\$s->hasSecretKey() ? 'PASS' : 'FAIL') . PHP_EOL;
"
```

**Result:** PASS — Service instantiates and key is recognized.

### 2. Checkout Session Creation

```bash
docker compose exec web php -r "
require '/var/www/vendor/autoload.php';
require '/var/www/src/config/app.php';
require '/var/www/src/services/StripeService.php';
\$s = StripeService::fromAppConfig(\$appConfig);
\$sess = \$s->createCheckoutSession(10.00, 'usd', 'Test Invoice', 'http://localhost/success', 'http://localhost/cancel');
echo 'session id: ' . (\$sess['id'] ?? 'FAIL') . PHP_EOL;
echo 'url: ' . (strpos(\$sess['url'] ?? '', 'https://checkout.stripe.com') === 0 ? 'PASS' : 'FAIL') . PHP_EOL;
"
```

**Result:** PASS — Session created successfully, returns Stripe Checkout URL.

### 3. Checkout Session with Surcharge

```bash
# Stripe fee $0.59 added to $10.00 invoice = $10.59 total
```

**Result:** PASS — Amount total = 1059 cents ($10.59) correctly calculated.

### 4. Webhook Endpoint (New)

```bash
curl -X POST "http://localhost:1627/?page=stripe-webhook" \
  -H "Content-Type: application/json" \
  -H "Stripe-Signature: whsec_test" \
  -d '{"id":"evt_test","type":"checkout.session.completed","data":{"object":{"id":"cs_test","amount_total":1000,"currency":"usd","metadata":{"invoice_id":"1"},"payment_status":"paid","status":"complete"}}}'
```

**Result:** PASS — Returns `{"received":true}` HTTP 200.

### 5. Webhook Endpoint (Legacy)

Same test as above with `page=stripe-webhook-legacy`.

**Result:** PASS — Returns `{"received":true}` HTTP 200.
Gracefully handles missing invoice with log message.

### 6. Payment Recording in Database

Simulated webhook with real invoice ID = 999001 (test data inserted via SQL).

**Result:** PASS — Payment row inserted with `client_id`, `invoice_id`, `amount`, `stripe_session_id`, `status='succeeded'`.

### 7. Invoice Status Update

After webhook processing, checked invoice status.

**Result:** PASS — Status changed from `sent` to `paid` (or `partial` for partial payments).

### 8. No Raw Card Data

**DB audit:**
- `clients` table: `stripe_customer_id` (token), `stripe_payment_method_id` (token)
- `payments` table: `stripe_session_id` (token), `stripe_payment_intent_id` (token)
- No columns named `card_number`, `cvv`, `cvc`, `card_expiry`, `credit_card`

**Code audit:**
- No `<input>` fields for raw card data
- All payment UI redirects to Stripe Checkout or uses Stripe Elements (client-side tokenization)

**Result:** PASS — Zero raw card data stored.

## Outstanding Items

| Item | Status | Notes |
|------|--------|-------|
| Webhook signature verification | PARTIAL | Code exists, but `stripe_webhook_secret_enc` is empty in test env |
| Auto-charge recurring | UNTESTED | Requires real `stripe_customer_id` + `stripe_payment_method_id` in DB |
| Refund flow | UNTESTED | `StripeService::createRefund()` exists but not exercised |
| Subscription billing | UNTESTED | `contracts.stripe_subscription_id` exists but unused in tests |
| Production live keys | N/A | Test keys only; live keys require Stripe activation |

## Next Steps

1. Configure `stripe_webhook_secret_enc` in `.env` and test signature verification
2. Create a client with `auto_pay_enabled=1` + `stripe_customer_id` and test recurring auto-charge
3. Test refund flow end-to-end
4. Add automated tests to CI pipeline for Stripe flows

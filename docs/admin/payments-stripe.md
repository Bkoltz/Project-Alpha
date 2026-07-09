---
title: Stripe Payments
description: Stripe setup, webhook behavior, and testing.
---

# Stripe Payments

PA can use Stripe for invoice and project invoice payments.

## Setup

1. Configure the publishable key, secret key, and webhook secret in **Settings > Billing**.
2. Create a Stripe webhook endpoint:

   ```text
   https://YOUR_PROJECT_ALPHA_HOST/stripe-webhook
   ```

3. Select at least `checkout.session.completed` and `payment_intent.succeeded`.
4. Use test mode until the full payment workflow has been verified.

## What PA Records

PA reconciles successful payments, records refunds and disputes where supported, updates invoice status, and can issue receipts.

## Project Invoice Payments

Project statement payments are allocated to child invoices. The project statement itself is not counted as additional revenue.

## Troubleshooting

Check the Stripe endpoint URL, HTTPS certificate, test/live mode, webhook secret, PA logs, and Stripe delivery attempts.


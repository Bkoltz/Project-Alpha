# Service Context

Last reviewed: 2026-06-28

Services contain reusable business or external-integration logic used by controllers and scheduled jobs.

## Current Services

- `EmailService.php`: application email convenience layer
- `LinkResolverService.php`: external document/folder link resolution
- `PaymentProcessorInterface.php`: payment processor boundary
- `StripeProcessor.php`: Stripe implementation of the processor boundary
- `StripeService.php`: Stripe customer, Checkout, Payment Intent, subscription, webhook, and reconciliation operations

## Rules

- Load credentials from the supported configuration path and decrypt only when needed.
- Return structured results or throw typed/meaningful exceptions; do not render views here.
- Keep Stripe amounts and currency-unit conversion explicit.
- Preserve invoice metadata used by reconciliation.
- Make external operations idempotent where providers permit.
- Do not log secrets or raw payment data.
- Add a processor through `PaymentProcessorInterface` instead of scattering provider conditions through controllers.

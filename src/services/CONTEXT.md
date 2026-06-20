# src/services — Context

Last updated: 2026-06-20 by Hermes

## What This Is

This folder holds reusable business-logic service classes. They are instantiated by controllers/cron scripts, perform external API calls or complex cross-table operations, and return arrays or throw exceptions on failure.

## Files

- `StripeService.php` — Stripe REST API client wrapper. Creates customers, payment intents, subscriptions, checkout sessions; verifies webhooks; and reconciles payment intents.
- `LinkResolverService.php` — Auto-generates and refreshes Dropbox/Google Drive/S3 public folder links for clients and organizations.

## Key Details

### StripeService

- Constructor: `StripeService(?string $apiKey, ?string $webhookSecret)`.
- Factory methods:
  - `fromAppConfig(array $appConfig): ?self` — decrypts `stripe_secret_key_enc`/`stripe_webhook_secret_enc` using `crypto_decrypt()`.
  - `fromPaymentMethod(PDO $pdo, int $paymentMethodId): self` — loads per-payment-method Stripe config from `payment_methods.config`.
- Key methods:
  - `createOrGetCustomer(PDO $pdo, int $clientId): string` — looks up or creates a Stripe customer and stores `clients.stripe_customer_id`.
  - `createPaymentIntent(...)` / `createPaymentIntentWithMetadata(...)` / `createSubscription(...)` / `createCheckoutSession(...)` / `createCheckoutSessionWithSurcharge(...)`.
  - `cancelSubscription(string $subscriptionId): array`.
  - `listPaymentIntents(int $since): array` — paginates up to 1000 Payment Intents created after a Unix timestamp.
  - `verifyWebhook(string $payload, string $signature): bool` — HMAC-SHA256 verification using `t=v1,...` Stripe header format.
- Uses raw cURL to `https://api.stripe.com/v1/`, flattening nested arrays to `metadata[key]=value` form encoding.
- Amounts are passed as dollars and converted to cents with `(int) round($amount * 100)`.

### LinkResolverService

- Constructor: `LinkResolverService(PDO $pdo)`.
- Loads feature flags from `app_config` (`link_resolver_enabled`, `default_link_expiration_days`, `org_level_links_only`) and provider credentials from `link_resolver_config`.
- Supported providers: `dropbox`, `gdrive`, `s3`. Each resolves via `src/link_resolvers/auto_resolver/{provider}_link_resolver.php`.
- Key methods:
  - `autoGenerateForClient(int $clientId): array`.
  - `autoGenerateForOrganization(int $orgId): array`.
  - `refreshLinks(string $entityType, int $entityId): array`.
  - `markAsIgnored(...)` / `unmarkAsIgnored(...)` / `expireLinks(...)`.
- Inserts/updates rows in `entity_links` (`entity_type`, `entity_id`, `link_type`, `url`, `expiration_date`, `is_expired`, `last_verified`).

## Dependencies

- `src/utils/crypto.php` (for Stripe secret decryption).
- `src/config/db.php`, `src/config/app.php` (typical callers).
- `src/link_resolvers/auto_resolver/*` (LinkResolverService provider implementations).
- External Stripe API; cURL (`ext-curl`).

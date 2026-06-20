# src/utils — Context

Last updated: 2026-06-19 by Hermes

## What This Is

This folder contains small, focused utility modules used across controllers, cron scripts, and views. Most files expose functions or small classes rather than route handlers.

## Files

- `crypto.php` — AES-256-GCM encrypt/decrypt helpers. Key loaded only from `APP_ENCRYPTION_KEY` env var.
- `csrf.php` — Legacy CSRF token generation/verification using session storage.
- `csrf_sf.php` — Symfony-backed CSRF wrapper that falls back to `csrf.php` if Symfony is unavailable.
- `mailer.php` — PHPMailer wrapper `mailer_send($cfg, $to, $subject, $html, $fromEmail, $fromName, $envelopeFrom, $attachments)`.
- `smtp.php` — Minimal raw SMTP client with STARTTLS/SSL and AUTH LOGIN fallback.
- `logger.php` — Monolog-based `app_logger($name)` plus simple `app_log($category, $message, $context)` and `audit_event(PDO, ...)`.
- `notifications.php` — Activity logging and admin email notifications for quote/contract/invoice events.
- `twig.php` — Twig environment factory `get_twig()` with `money`/`date_format` filters, `csrf_token()`/`url()` functions.
- `rate_limiter.php` — Sliding-window rate limit `rate_limit_check(PDO, $key, $max, $windowSeconds)` for public link access.
- `security_headers.php` — `send_security_headers()` sets CSP, HSTS, frame options, etc.
- `api_auth.php` — API key Bearer/X-Api-Key authentication, scope/allowlist/rate-limit checks.
- `password_policy.php` — `password_policy_error($password)` enforces 8+ chars with upper/lower/digit/special.
- `two_factor_auth.php` — TOTP/backup-code utility class `App\Utils\TwoFactorAuth`.
- `audit.php` — `audit_log(PDO, $action, $entityType, $entityId, $details, $userId)` writes to `system_audit`.
- `audit_middleware.php` — Router-level shutdown hook that writes baseline audit rows for sensitive pages.
- `upload_validator.php` — `validate_upload($file, $allowedMimes, $maxBytes)` returns null or error string.
- `document_fields.php` — Render/extract custom document fields and deposit composites.
- `format.php` — `format_phone($raw)` formats North-American phone numbers.
- `project_id.php` — Project ID helpers.
- `webhook_logger.php` / `cron_logger.php` — Additional log helpers.
- `StripeFeeCalculator.php` — Calculates Stripe fees and merchant/client/split surcharge breakdowns.
- `InvoiceSurcharge.php` — Applies the surcharge to an invoice row when payment method is card/Stripe.

## Key Details

- **crypto.php** format: `enc::<iv_b64>:<tag_b64>:<ciphertext_b64>`. Accepts a raw base64 32-byte key or derives via SHA-256.
- **csrf_sf.php** writes the Symfony token into `$_SESSION['csrf']` so legacy code still works.
- **mailer.php** returns `[bool $ok, string $error]`; falls back to plain `mail()` if PHPMailer is missing.
- **smtp.php** is used as fallback when PHPMailer fails; supports `tls`, `ssl`, `none`.
- **logger.php** is partially duplicated by `src/config/logging.php`; both write JSON or line logs to `logs/`.
- **notifications.php** sends admin emails via `mailer_send`/`smtp_send` and logs to `activity_log`.
- **twig.php** currently enables debug mode and no template cache; production should enable a cache dir.
- **api_auth.php** records usage in `api_usage`, enforces per-minute `API_RATE_LIMIT_PER_MIN` (default 60), and supports CSV scopes.
- **audit_middleware.php** must be called once per request from `public/index.php`; it captures `$_SESSION['user']['id']` and common IDs at shutdown.
- **upload_validator.php** validates by real MIME via `finfo`, not the browser-provided type.
- **StripeFeeCalculator.php** supports `merchant`, `client`, and `split` surcharge types; `split` respects `stripe_surcharge_split_percent`.

## Dependencies

- Composer packages: PHPMailer, Monolog, Symfony Security CSRF, Twig, Dompdf.
- PHP extensions: `pdo`, `openssl`, `curl`, `fileinfo`.
- Database tables: `api_keys`, `api_usage`, `system_audit`, `activity_log`, `rate_limits`, `document_custom_fields`.
- Environment: `APP_ENCRYPTION_KEY`, `API_RATE_LIMIT_PER_MIN`.
- External: Stripe API (via `StripeService`), SMTP servers.

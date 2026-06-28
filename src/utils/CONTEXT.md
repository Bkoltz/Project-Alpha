# Utility Context

Last reviewed: 2026-06-28

Utilities provide shared security, formatting, billing, logging, rendering, upload, and data-access helpers.

Important modules include:

- `acl.php` and `acl_middleware.php`: authorization and record scope
- `csrf.php` and `csrf_sf.php`: browser request tokens
- `crypto.php`: AES-256-GCM helpers using `APP_ENCRYPTION_KEY`
- `rate_limiter.php`: database-backed throttling
- `client_ip.php`: trusted-proxy-aware client address resolution
- `upload_validator.php`: MIME, size, filename, and optional scan handling
- `audit.php` and `audit_middleware.php`: security and business-event records
- `mailer.php` and `smtp.php`: email delivery
- `twig.php`: Twig environment and helpers
- `recurring_billing.php` and `project_billing.php`: billing calculations and invoice generation
- `StripeFeeCalculator.php` and `InvoiceSurcharge.php`: fee and surcharge calculations
- `webhook_logger.php` and `cron_logger.php`: integration and job logs

## Rules

- Keep utilities focused and free of page rendering or redirects unless that is their explicit contract.
- Validate untrusted values at the boundary and return clear errors.
- Treat proxy headers as trusted only for configured proxy CIDRs.
- Never add default secrets or production-specific values.
- Update callers and tests when changing a shared function signature.

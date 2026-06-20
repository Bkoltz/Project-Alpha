# src/controllers — Context

Last updated: 2026-06-19 by Hermes

## What This Is

This folder contains all HTTP request handlers for Project Alpha. Routing is centralized in `public/index.php`, which maps the `page` query parameter to a controller file here. Controllers perform POST/GET handling, database writes, redirects, JSON responses, PDF rendering, or Stripe/webhook processing.

## Routing Pattern

`public/index.php` parses `$_GET['page']`, sanitizes it, enforces auth/session/CSRF, then `require_once`s the matching controller. Routes support both slash-prefixed names (e.g., `client/clients-create`) and legacy dash names (e.g., `clients-create`). API endpoints use an `api-` prefix and header-based auth.

## Controllers by Area

### Authentication & Accounts
- `auth/auth_handler.php` — Login and first-admin registration. Enforces throttling, password policy, 2FA redirect, force-password-reset redirect.
- `auth/two_factor_setup.php` / `auth/two_factor_verify.php` — TOTP setup/verification controllers.
- `auth/reset_request.php` / `auth/reset_verify.php` / `auth/reset_update.php` — Password reset flow.
- `auth/account_update.php` — Update current user profile/password.
- `accounts/accounts_create.php` / `accounts_update.php` / `accounts_delete.php` / `accounts_reset_password.php` — Admin user management.
- `account_revoke_device.php` — Revoke remembered sessions/devices.

### Clients, Organizations, Projects
- `client/clients_create.php` / `clients_update.php` / `clients_delete.php` / `clients_restore.php` / `clients_purge.php` / `clients_search.php` — Client CRUD and AJAX search.
- `organization/organizations_create.php` / `organizations_update.php` / `organizations_delete.php` / `org_search.php` / `organization_add_client.php` / `organization_remove_client.php` / `organization-update-notes.php` / `organizations_upload.php` / `org_create.php` — Organization CRUD, client associations, notes, file uploads.
- `project/projects_create.php` / `projects_update.php` / `projects_delete.php` / `projects_update_status.php` / `project_add_document.php` / `project_remove_document.php` / `projects_search.php` / `projects_search_autocomplete.php` / `project_notes.php` / `project_notes_update.php` — Project lifecycle and document management.

### Quotes, Contracts, Invoices, Payments
- `quote/quotes_create.php` / `quotes_update.php` / `quote_approve.php` / `quote_reject.php` / `quote_pdf.php` — Quote CRUD and client actions.
- `contract/contracts_create.php` / `contracts_update.php` / `contract_sign.php` / `contract_complete.php` / `contract_void.php` / `contract_deny.php` / `contract_deposit_received.php` / `contract_pdf.php` — Contract CRUD and lifecycle.
- `contract/long_term_contracts_create.php` / `long_term_contract_activate.php` / `long_term_contract_pause.php` / `long_term_contract_resume.php` / `long_term_contract_terminate.php` — Long-term contract actions.
- `contract/on_demand_contracts_create.php` / `on_demand_contract_activate.php` / `on_demand_contract_pause.php` / `on_demand_contract_resume.php` / `on_demand_contract_terminate.php` / `on_demand_invoice_generate.php` — On-demand contract actions.
- `invoice/invoices_create.php` / `invoices_update.php` / `invoices_mark_paid.php` / `invoice_pdf.php` — Invoice CRUD and marking paid.
- `payments_create.php` — Record manual payments.

### Stripe & Public
- `stripe/stripe_checkout.php` / `stripe_charge.php` / `stripe_success.php` — Payment flow and success handling.
- `webhook/stripe_webhooks.php` / `webhook/stripe_payment_succeeded.php` / `webhook/stripe_payment_failed.php` / `webhook/stripe_checkout_completed.php` / `stripe/stripe_webhook.php` — Webhook receivers (new and legacy).
- `public_view/public_doc.php` / `public_quote_action.php` / `public_contract_sign.php` / `public_contract_action.php` / `public_redirect.php` — Public link views and actions.
- `public_link_create.php` / `public_link_revoke.php` — Internal public link management.

### Settings, Forms, Receipts, Email
- `settings_handler.php` — Main settings save handler; delegates `tab=links` and encrypts secrets to `.env`.
- `settings/links_handler.php` / `link_test_connection.php` / `dropbox_oauth.php` / `tax-rates-handler.php` / `tax-import-handler.php` / `tax-rates-import-handler.php` / `item_library_handler.php` / `item_library_search.php` / `custom_fields_handler.php` / `document-custom-fields-handler.php` / `document-customization-save.php` — Settings sub-handlers.
- `forms_handler.php` — JSON endpoint for form/category/document uploads and deletion.
- `receipts_handler.php` — JSON endpoint for receipt create/update/delete with file uploads.
- `email_send.php` — Sends quotes/contracts/invoices with public link and optional PDF attachment.
- `email_test.php` — Test email controller.
- `backup_handler.php` — Manual backup trigger from settings.
- `serve_upload.php` — Serves uploaded files with granular access control.

### API, Audit, Financial, Links
- `api/custom_fields_ajax.php` — AJAX endpoint for custom fields.
- `api_keys_create.php` / `api_keys_revoke.php` — API key management.
- `financial/financial_api.php` — Dashboard data API.
- `financial/audit_export.php` / `audit_schedule_handler.php` — Audit CSV export and schedule handler.
- `links/link_management.php` / `manual_link_handler.php` — Link management UI actions.

## Key Details

- Most POST endpoints validate CSRF via `csrf_verify_post_or_redirect($page)` in `public/index.php`. Skipped endpoints include auth, public actions, webhooks, and `settings/link-test-connection`.
- Session timeout is 8 hours of inactivity; `AUTH_DISABLED`/`APP_AUTH_DISABLED` can bypass auth in dev.
- Controllers typically output a JSON response with `success`/`message`/`redirect` keys or perform a PRG redirect with query flags (`saved=1`, `error=...`).
- PDF generation controllers set `PDF_MODE` and render a print view through Dompdf.
- Public controllers use `rate_limit_check()` from `src/utils/rate_limiter.php`.
- `email_send.php` creates a fresh `public_links` token and appends a Stripe Pay button when the invoice is payable.
- Sensitive actions are audited either explicitly via `audit_log()` or automatically via `src/utils/audit_middleware.php`.

## Dependencies

- `public/index.php` router.
- `src/config/db.php`, `src/config/app.php`.
- `src/utils/csrf.php` / `csrf_sf.php`, `src/utils/crypto.php`, `src/utils/mailer.php`, `src/utils/notifications.php`.
- `src/services/StripeService.php`, `src/services/LinkResolverService.php`.
- Database tables: `users`, `clients`, `organizations`, `projects`, `quotes`, `contracts`, `invoices`, `payments`, `public_links`, `entity_links`, `receipts`, `form_categories`, `form_documents`, etc.
- External: Stripe API, SMTP/PHPMailer, Dompdf, Dropbox/Google Drive/S3 link resolvers.

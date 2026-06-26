# Project Alpha (PA)

A PHP 8.3 business-document SaaS for quotes, contracts, invoices, receipts, and payments. Built around a single organization with role-based access control, audit logging, optional 2FA, and a pluggable payment processor interface (Stripe now, Square-ready). Features Stripe Payment Intents, customizable permissions, and document workflows.

---

## What's Working

- **Quotes, Contracts, and Invoices**
  - Create regular, long-term, and on-demand quotes and contracts.
  - Convert quotes to contracts and contracts to invoices.
  - Recurring invoice generation for long-term contracts.
  - Public shareable document links with expiration.
  - Document re-enablement (un-void) for voided contracts/invoices.

- **Payments**
  - Stripe Payment Intents (not Stripe Invoices).
  - Public invoice payment pages.
  - Admin-initiated card charges.
  - Webhook handling for payment confirmations.
  - Stripe reconciliation for missed payments.

- **Receipts, Expenses, and Financial Tracking**
  - Receipt upload and categorization.
  - Expense tracking with categories, vendors, and mileage.
  - Financial dashboard with income, expenses, net profit, mileage deductions, receipts, category breakdown, top vendors, and recent expenses.
  - Basic CSV expense reports.
  - Forms and document storage (W-9, W-8, etc.).

- **Taxes and Items**
  - Tax-rate management with jurisdiction support.
  - Custom tax calculations per document.
  - Item library for reusable line items.

- **Users, Auth, and Security**
  - Session-based login with CSRF protection.
  - Optional 2FA (TOTP) with backup codes.
  - Role-based access (`admin` / `user`).
  - Login rate limiting (IP and per-account).
  - Password policy enforcement.
  - Router-level audit logging for sensitive actions.
  - InnoDB tablespace encryption at rest.
  - Apache hardening headers and CSP.

- **Settings and Admin**
  - Organization, billing, email, legal, and document settings.
  - User management and audit log.
  - Backup/restore scripts.

- **API**
  - Basic JSON data endpoints (auth required for protected resources).
  - Webhook receiver for Stripe (no auth).

---

## Known Limitations / Not Yet Working

- **Organization branding**: Single-organization only; name, logo, and contact info default from global config with optional override via Settings.
- **Advanced reporting**: Only basic CSV exports and the financial dashboard are available. There is no built-in P&L, balance sheet, or custom chart builder.
- **Recurring invoices**: Long-term contracts generate invoices automatically, but each generated invoice still requires normal review/sending workflow.
- **Mileage IRS form auto-fill**: Mileage logs can be tracked and valued, but automatic IRS form generation/population is not implemented.
- **Vendor bill-pay integration**: Vendors are tracked for expenses only; there is no direct ACH/check bill-pay integration.
- **Public client portal**: Clients can view public document links and pay invoices, but there is no persistent client login portal.
- **Mobile app / PWA**: The UI is responsive but not packaged as a PWA or native app.

---

## Quick Start

Project Alpha uses inline defaults in `docker-compose.yml`; no `.env` file is required.

1. Review `docker-compose.yml` — it uses inline defaults and no `.env` file is required. Change `ADMIN_PASSWORD` and the MySQL passwords from their defaults.
2. Pull the GitHub Container Registry images and start the stack:

```bash
docker compose pull
docker compose up -d
```

3. Run migrations once:

```bash
docker compose exec -T web php /var/www/src/migrations/run_migrations.php --verbose
```

4. Open http://localhost:1627 and log in with:
   - **Email**: `admin@project-alpha.local`
   - **Password**: the `ADMIN_PASSWORD` value you set in `docker-compose.yml`
5. Go to **Settings → Billing** and enter your Stripe keys. They are encrypted with the auto-generated `APP_ENCRYPTION_KEY` (persisted to `./config/.encryption_key`) and saved in the `app_config` DB table.

### Admin Login

If the admin account is not auto-seeded, the login page will show a **Create First Admin** form when the `users` table is empty.

---

## Workflow Summary

```
Quote → Contract → Invoice → Payment
```

1. **Quote**: Create and send a quote (regular, long-term, or on-demand). The client can view it via a public link.
2. **Contract**: Approve the quote to generate a contract. Long-term contracts define a recurring billing schedule.
3. **Invoice**: Generate invoices from contracts manually or through recurring generation. Invoices can be sent with public payment links.
4. **Payment**: Clients pay via Stripe Payment Intents; admins can also charge a saved card or record offline payments.

Financial activity (income, expenses, receipts, and mileage) is summarized on the **Financial Dashboard**.

---

## Architecture Snapshot

- **Backend**: PHP 8.3, no framework, Composer-managed dependencies (Twig 3, Monolog, Stripe SDK, etc.).
- **Database**: MySQL 8 with InnoDB tablespace encryption at rest.
- **Frontend**: Plain HTML/PHP templates, scoped CSS, vanilla JS; no build step.
- **Payments**: Stripe Payment Intents and Checkout; webhook receiver at `/?page=stripe-webhook`.
- **Cron**: PHP scripts run via host/container cron for recurring invoices, reminders, reconciliation, contract termination, and link expiration.
- **Deployment**: Docker Compose with Apache; no `.env` required, optional `.env` for secret management.

---

## Supported Document Types

| Type | Prefix | Description |
|------|--------|-------------|
| Regular Quote | Q-XXX | One-time project quoting |
| Long-term Quote | LTQ-XXX | Recurring service quotes |
| On-Demand Quote | ODQ-XXX | Flexible quoting without intervals |
| Regular Contract | C-XXX | One-time service contracts |
| Long-term Contract | LTC-XXX | Subscription/retainer contracts |
| On-Demand Contract | ODC-XXX | Flexible billing contracts |
| Invoice | I-XXX | Standard invoices |
| On-Demand Invoice | ODI-XXX | Manual invoice generation |

---

## Stripe Integration

PA uses **Stripe Payment Intents** (not Stripe Invoices) for card payments. This avoids Stripe invoice fees while maintaining PCI compliance.

### Configuration

1. Go to **Settings → Billing** in the PA admin.
2. Enter your Stripe keys:
   - **Publishable Key**: `pk_live_...` or `pk_test_...`
   - **Secret Key**: `sk_live_...` or `sk_test_...`
   - **Webhook Secret**: `whsec_...`

### Webhook Setup

Configure your Stripe webhook to send events to:

```
https://your-domain.com/?page=stripe-webhook
```

**Required events:**
- `checkout.session.completed` - Handles Stripe Checkout payments
- `payment_intent.succeeded` - Handles direct Payment Intent payments (auto-pay)

**Optional events:**
- `payment_intent.payment_failed` - Log failed payments
- `charge.refunded` - Handle refunds
- `charge.dispute.created` - Handle disputes

### Metadata Convention

All Payment Intents include metadata for reconciliation:

```json
{
  "pa_invoice_id": "12345",
  "invoice_id": "12345",
  "doc_number": "1001"
}
```

The `pa_invoice_id` is the primary identifier used for linking Stripe payments to PA invoices.

### Payment Flow

1. **Public Invoice Payment**: Client clicks "Pay with Card" on a public invoice link.
2. **Admin Card Charge**: Admin clicks "Charge Card" from invoice details.
3. **Record Payment (Stripe)**: Admin selects Stripe from the payment-methods dropdown.

All methods redirect to Stripe Checkout, and the webhook updates the invoice status automatically.

### Credit Card Surcharge (Compliant)

PA includes a compliant credit-card surcharge system with three modes controlled from **Settings → Billing**:

- **Merchant absorb**: PA absorbs the processing cost (default).
- **Client pays**: Customer pays the full processing fee.
- **Split**: Customer pays a configurable portion of the fee.

The surcharge amount is capped at the actual blended merchant processing rate, which is computed daily from Stripe `balance_transaction` fees. The surcharge never exceeds the real fee paid to the processor, satisfying Visa/Mastercard compliance rules.

**Debit card protection**: The Stripe webhook detects the card funding type (`credit` vs `debit`/`prepaid`). If a surcharge was applied to a debit or prepaid card, it is automatically refunded to the customer (Durbin Amendment compliance). Migration `020` adds `surcharge_refunded` and `surcharge_refund_amount` columns to the `payments` table.

**Disclosure**: A pre-payment surcharge notice is displayed on the public invoice payment page before the customer clicks Pay.

**Pluggable processor interface**: Surcharge logic is built around `PaymentProcessorInterface` in `src/services/`. Stripe is fully implemented; Square support is architected and ready for integration.

> **Note**: Enabling client/split surcharging requires Visa registration and a 30-day notice before deployment. Configure and enable only after completing merchant/processor registration.

---

## Cron Jobs

PA uses scheduled cron jobs for automated tasks. All jobs track their execution in the `cron_job_runs` table for catch-up after downtime.

### Available Jobs

| Job | Description | Recommended Schedule |
|-----|-------------|---------------------|
| `generate_recurring_invoices.php` | Creates invoices for long-term contracts | Daily at midnight |
| `send_invoice_reminders.php` | Sends due/overdue reminders | Daily |
| `stripe_reconciliation.php` | Syncs missed Stripe payments | Every 6 hours |
| `auto_terminate_contracts.php` | Ends expired contracts | Daily |
| `link_expiration_checker.php` | Expires old public links | Daily |
| `sync_merchant_rate.php` | Computes actual blended Stripe merchant rate from recent balance transactions | Daily at 5:00 AM UTC |

### Cron Configuration (Docker)

Add to your container's crontab:

```cron
# Generate recurring invoices (daily at midnight)
0 0 * * * php /var/www/src/cron/generate_recurring_invoices.php >> /var/log/cron.log 2>&1

# Send invoice reminders (daily at 9am)
0 9 * * * php /var/www/src/cron/send_invoice_reminders.php >> /var/log/cron.log 2>&1

# Stripe reconciliation (every 6 hours)
0 */6 * * * php /var/www/src/cron/stripe_reconciliation.php >> /var/log/cron.log 2>&1

# Auto-terminate contracts (daily at 1am)
0 1 * * * php /var/www/src/cron/auto_terminate_contracts.php >> /var/log/cron.log 2>&1

# Link expiration check (daily at 2am)
0 2 * * * php /var/www/src/cron/link_expiration_checker.php >> /var/log/cron.log 2>&1

# Sync blended merchant rate from Stripe balance transactions (daily at 5am UTC)
0 5 * * * php /var/www/src/cron/sync_merchant_rate.php >> /var/log/cron.log 2>&1
```

### Catch-Up & Resilience

If PA goes offline, cron jobs automatically catch up on missed work:

1. **Invoice Generation**: Uses `next_invoice_date` on contracts to generate all missed invoices.
2. **Stripe Reconciliation**: Fetches Payment Intents from Stripe API since last run, records any missed payments.
3. **Reminders**: Uses the `invoice_notifications` table to avoid duplicate sends.

The `cron_job_runs` table tracks:
- `job_name`: Unique identifier
- `last_run`: Timestamp of last successful run
- `status`: success/failed
- `error_message`: Details if failed

---

## Database Schema

### Key Tables

- **`invoices`**: Invoice records with `invoice_type` (regular/on_demand), `contract_id`, status tracking
- **`payments`**: Payment records with `stripe_payment_intent_id` for reconciliation
- **`contracts`**: Unified contracts table with `contract_type` column (regular/long_term/on_demand). Replaces old separate `long_term_contracts`/`on_demand_contracts` tables.
- **`quotes`**: Unified quotes table with `quote_type` column (regular/long_term/on_demand). Replaces old `is_long_term`/`is_on_demand` boolean columns.
- **`document_custom_fields`**: Seeded with `deposit` (Deposit Required) and `fulfillment_date` (Fulfillment Date Estimated) for all 3 document types.
- **`public_links`**: Shareable document links with expiration
- **`cron_job_runs`**: Cron execution tracking for catch-up logic
- **`invoice_notifications`**: Tracks sent reminders (idempotency)
- **`tax_rates`**: Predefined tax rates per jurisdiction
- **`system_audit`**: Audit log for critical system actions (immutable)
- **`organizations`**: Single default organization with tax-exempt form storage and `brand_*` overrides resolved against global config.
- **`user_organizations`**: Links users to the default organization with a role and role-based permissions.
- **`clients`**: Client records with archived status
- **`projects`**: Manual parent grouping for jobs
- **`project_counters`**: Auto-generated project codes
- **`api_keys`**: API key management
- **`entity_links`**: External resource links (Dropbox, Google Drive, S3)
- **`expenses`**, **`expense_categories`**, **`vendors`**, **`mileage_logs`**, **`receipts`**, **`form_categories`**, **`form_documents`**: Financial records

### Payments Table Columns

```sql
id, invoice_id, amount, method, check_number, notes,
stripe_payment_intent_id, stripe_subscription_id, stripe_charge_id,
auto_pay_attempt, payment_method_id, status,
surcharge_paid, surcharge_refunded, surcharge_refund_amount,
created_at
```

---

## Environment Variables

All variables have inline defaults in `docker-compose.yml`. No `.env` file is required — just change the 3 passwords.

| Variable | Required | Description |
|----------|----------|-------------|
| `ADMIN_PASSWORD` | **Yes** | Initial admin password. Hashed on container start and used to create/reset the admin account |
| `MYSQL_ROOT_PASSWORD` | **Yes** | MySQL root password |
| `MYSQL_PASSWORD` | **Yes** | MySQL app user password |
| `TRUSTED_PROXIES` | No | CIDR ranges for reverse proxy IP detection (default: Docker internal networks + Cloudflare) |

Docker-specific environment variables (hardcoded in compose, not user-configurable):

| Variable | Purpose |
|----------|---------|
| `DB_HOST` | MySQL host (default: `db`) |
| `DB_PORT` | MySQL port (default: `3306`) |
| `MYSQL_DATABASE` | Database name (default: `project_alpha`) |
| `MYSQL_USER` | DB app user (default: `appuser`) |

Auto-managed (do NOT set in compose):
- `APP_ENCRYPTION_KEY` — Auto-generated by `docker/start.sh` on first run, persisted to `./config/.encryption_key`
- Stripe keys — Entered via **Settings > Billing** UI, stored encrypted in `app_config` DB table
- SMTP password — Entered via Settings UI, stored encrypted in DB

---

### Version Display

Each Docker image is stamped with a build version from `git describe` via the `APP_VERSION` build argument. The version is shown in the site footer and exposed through the `api-dashboard-summary` endpoint for external monitoring (e.g. the Command Center).

### Deployment (CI/CD)

PA is distributed as prebuilt images from the GitHub Container Registry:

- `ghcr.io/ledgetoptechnologies/project-alpha:latest` — web (Apache) image
- `ghcr.io/ledgetoptechnologies/project-alpha:cron-latest` — cron (CLI) image

**Staging**: Pull and run the `:dev` / `:cron-dev` tags on port `1628` for pre-production testing before promoting to production.

**CI / smoke-test**: `.github/workflows/ci.yml` builds the stack from source and asserts:

- Web image identity
- DB connectivity over TCP
- CSP-clean responses over HTTP
- PHP syntax check
- PHPUnit test run

**Branch protection**: The `main` branch is protected — changes require a pull request and a passing smoke-test check before merging. Auto-merge is enabled.

**Production**: Deployed as a TrueNAS Custom App on port `1627`, pulling `:latest`. Redeploy with Pull + Up. Production uses the multi-stage Dockerfile:

- `AS vendor` — installs Composer dependencies
- `AS web` — based on `php:8.3-apache`
- `AS cron` — based on `php:8.3-cli`

---

## Advanced Features

### Custom Fields

PA supports customizable document fields:

- **Field Types**: Text short/long, Number, Date
- **Per-Org Configuration**: Each organization can define their own fields
- **Validation**: Required toggle, min/max for numbers
- **Auto-Population**: Default values can be set per field

### Tax Management

- **Predefined Tax Rates**: Store tax rates by country/state/county
- **Auto-Selection**: System selects appropriate rate based on document context
- **Manual Override**: Users can enter custom tax percentages when needed
- **Tax Tracking**: Records `tax_amount` and `tax_county` for audit compliance

### Receipt Management

- Upload images of receipts (JPEG, PNG, PDF)
- Track date, amount, and description
- Store in `/uploads/receipts/`
- Search and filter by date range, amount, client

### Forms & Documents Storage

- Upload and organize forms (W-9, W-8, etc.)
- Store in `/uploads/forms/`
- Email forms directly to clients/organizations
- View/download/replacement capabilities

### Audit System

- Generate CSV, PDF, or ZIP reports
- Include/exclude invoices, contracts, quotes
- Auto-select current year (or custom date range)
- Quick presets: Last Quarter, Last Month, All Time, Current Year
- Scheduled email delivery (up to 5 recipients)
- Read-only audit records (immutable)

### Logging System

- Structured JSON logs with rotation
- User actions logged (document changes, status transitions)
- System events logged (cron jobs, sync tasks)
- Security events logged (login attempts, permission changes)
- 10MB rotation, 30 files retained, archival to cold storage

---

## Troubleshooting

### Stripe Payments Not Recording

1. Check webhook is configured in Stripe Dashboard
2. Verify webhook secret matches in PA settings
3. Check `/?page=stripe-webhook` is accessible (no auth)
4. Review error logs: `docker compose logs web`
5. Run manual reconciliation: `docker compose exec web php /var/www/src/cron/stripe_reconciliation.php`

### Missed Payments After Downtime

Run manual reconciliation:

```bash
docker compose exec web php /var/www/src/cron/stripe_reconciliation.php
```

### Cron Jobs Not Running

1. Verify `cron_enabled` is set to `true` in settings
2. Check cron service is running in container
3. Review `cron_job_runs` table for errors:

```sql
SELECT * FROM cron_job_runs ORDER BY updated_at DESC;
```

### Document Re-enablement Not Working

- Ensure you're on a recent version (requires `document_date` columns)
- Re-enabling a voided contract will also restore its linked invoice
- The document date will be updated to current date on re-enable
- Related documents (invoices from contracts) are automatically restored

---

## File Structure

```
public/
├── index.php           # Single entry point, routing
├── assets/
│   ├── styles.css      # Main's 113-line CSS + appended dev-only component classes
│   ├── js/             # All page-logic JS files (consolidated from public/js/)
│   ├── navigation.js   # SPA-like navigation handler
│   └── item-autocomplete.js
src/
├── config/             # Database and app configuration
├── controllers/        # Request handlers
│   ├── auth/           # Auth controllers (login, 2FA, password reset)
│   ├── contract/       # Contract CRUD + LT/OD actions
│   ├── invoice/        # Invoice CRUD
│   ├── financial/      # Expense, audit, CSV import handlers
│   ├── stripe/         # Stripe payment handlers
│   ├── webhook/        # Stripe webhook receivers
│   ├── public_view/    # Public document views
│   └── settings/       # Settings sub-handlers (links, tax, custom fields)
├── cron/               # Scheduled job scripts
├── migrations/         # Database migrations
├── services/           # Business logic (StripeService, LinkResolverService)
├── utils/              # Helpers (crypto, csrf, mailer, logger, twig, client_ip, upload_validator, audit)
└── views/              # HTML templates
    ├── pages/          # Page templates (organized by domain)
    ├── partials/       # Shared layout (header with mobile topbar, footer with ToS links)
    ├── templates/      # Twig templates and components
    │   ├── layouts/
    │   └── components/
    └── uploads/        # User uploads (receipts, forms, etc.)
database/
└── init.sql            # Unified schema with all modules + custom field seed data
docs/                   # Technical documentation
work_flow/              # Business workflow documentation
tools/                  # Backup/restore scripts, audit generator
```

---

## Contributing and Development

### Getting Started

1. Clone the repository
2. Run `docker compose pull && docker compose up -d`
3. Run migrations: `docker compose exec -T web php /var/www/src/migrations/run_migrations.php --verbose`
4. Open http://localhost:1627 and log in

### Code Style

- PHP 8.3+ required
- PSR-4 autoloading via Composer
- Use Twig for templating where possible
- Follow existing controller/view naming conventions

### Running Tests

```bash
composer install
vendor/bin/phpunit --colors=always
```

### API Endpoints

#### Public Endpoints (No Auth Required)

| Route | Description |
|-------|-------------|
| `/?page=public-doc&token=...` | View public quote/contract/invoice |
| `/?page=stripe-checkout&token=...` | Initiate Stripe payment |
| `/?page=stripe-success&token=...` | Payment success page |
| `/?page=stripe-webhook` | Stripe webhook receiver |

#### Admin Endpoints (Auth Required)

| Route | Description |
|-------|-------------|
| `/?page=stripe-charge&invoice_id=...` | Admin-initiated card charge |
| `/?page=public-link-create` | Generate shareable link |
| `/?page=payments/payments-create` | Record payment (supports Stripe redirect) |

---

## Security

### Reporting Vulnerabilities

To report any security vulnerabilities, send an email to bkoltz@ledgetoptechnologies.com with as much detail as possible. Please avoid creating any public issues before notifying us of any vulnerabilities. All vulnerabilities will be treated as highest priority with fixes provided within a couple of days of receiving all required information.

### Secret Management

Sensitive values (Stripe keys, SMTP password, encryption key) are **never stored in committed files**. They are either:
- Entered via the Settings UI and encrypted with AES-256-GCM before being stored in the `app_config` DB table, or
- Supplied as environment variables through `docker-compose.yml` (or an optional `.env` file) when the container starts.

| What | Where | Notes |
|------|-------|-------|
| Stripe keys | Encrypted in DB | Entered via Settings → Billing |
| Encryption key | `APP_ENCRYPTION_KEY` env var, auto-generated on first run | Persisted to `./config/.encryption_key` |
| SMTP password | Encrypted in DB | Entered via Settings UI |
| MySQL passwords | `docker-compose.yml` (or optional `.env`) | Change defaults before deploying |

- `docker-compose.yml` contains inline defaults — no `.env` file is required.
- An optional `.env` file can still be used to keep secrets out of the compose file.
- `.gitignore` blocks `config/settings.json`, `.env`, upload directories, and generated key files.
- `.gitleaksignore` prevents previously-rotated secrets from flagging CI.

### Authentication & Authorization

- **Session-based login** with CSRF protection (Symfony token, legacy fallback).
- **First-admin registration**: When the `users` table is empty, the login page shows a "Create First Admin" form (no manual DB inserts needed). The Docker startup path also seeds the admin from `ADMIN_PASSWORD` in `docker-compose.yml`.
- **Password policy**: Minimum 8 characters, mixed case, digit, and special character required; enforced on register, reset, and account update.
- **Rate limiting**: IP-based (15 attempts / 10 min) and per-account (5 attempts / 15 min) lockout on failed logins.
- **Role-based access**: Permission groups assigned by role (`owner`, `admin`, `staff`, `member`) with user-level overrides for sensitive pages and controllers.
- **2FA (TOTP)**: Optional two-factor authentication via authenticator app; backup codes provided.
- **Audit middleware**: Router-level logging of all sensitive actions (payments, password resets, 2FA changes, API key create/revoke, deletes, contract sign/complete, email send, PDF export, Stripe webhooks).

### Database Encryption

- **Encryption at rest** (InnoDB tablespace encryption via MySQL 8.4 `component_keyring_file`).
  - Manifest + component config bind-mounted read-only; keyring data in a dedicated named volume.
  - `default_table_encryption=ON`, redo + undo log encryption enabled.
  - All tablespaces verified encrypted.
  - See `docs/ENCRYPTION_AT_REST.md` for operational gotchas and backup requirements.
- **Application-level encryption**: Secrets are encrypted with AES-256-GCM before DB storage using the `APP_ENCRYPTION_KEY`.

### Container & Network Hardening

- **Docker Compose**: `docker-compose.yml` uses inline defaults; change `ADMIN_PASSWORD` and the MySQL passwords before deploying. No `.env` file is required, but an optional `.env` can still be used.
- **Network segmentation**: MySQL port is NOT mapped to the host by default — DB is reachable only inside the Docker internal network.
- **Apache hardening** (always on, not conditional):
  - `ServerTokens Prod` + `ServerSignature Off` — no version leakage.
  - `expose_php=Off` — removes `X-Powered-By` header.
  - Security headers: `X-Content-Type-Options`, `X-Frame-Options`, `X-XSS-Protection`, `Referrer-Policy`, `Strict-Transport-Security` (when HTTPS), and CSP.
- **ZAP baseline scan**: Run against the compose network; HTML report archived in `docs/zap_baseline_2026-06-11.html`. Remaining findings are informational (session cookies, COEP, non-storable content) — acceptable for an authenticated app.

### Backup & Recovery

- `tools/db_backup.sh` — mysqldump to timestamped `.sql.gz` with automatic rotation (keeps last 7 days).
- `tools/db_restore.sh` — restore from a backup dump.
- The **keyring volume MUST be backed up separately** — without it, encrypted data files are unrecoverable.

### Security Documentation in `docs/`

| File | Topic |
|------|-------|
| `AGENTS.md` | Development guidance for AI assistants |
| `AUTH_MIGRATION.md` | Auth folder reorganization summary |
| `ENCRYPTION_AT_REST.md` | InnoDB tablespace encryption setup & gotchas |
| `IMPLEMENTATION_ORDER.md` | Feature implementation sequence |
| `PROGRESS_SUMMARY.md` | Completed tasks and status |
| `RECURRING_INVOICES_SETUP.md` | Recurring billing guide |
| `ON_DEMAND_CONTRACTS_README.md` | On-demand features |
| `FILTER_MIGRATION_SUMMARY.md` | Template migration notes |
| `SECURITY.md` | Security contact info |
| `zap_baseline_2026-06-11.html` | OWASP ZAP baseline scan report |

---

## License

Proprietary - All rights reserved.

---

## Recent Updates (2025-2026)

### Feature Updates

- Re-enabled document re-enablement (un-void) for voided contracts/invoices
- Fixed document date display to show creation date instead of current date
- Added "Update Document Date" button for manual date extension
- Implemented On-Demand contract/quote type with flexible billing
- Added tax rates table with jurisdiction support (country/state/county)
- Created audit report system with CSV/PDF generation and email scheduling
- Added receipt management system for business expense tracking
- Implemented forms & docs storage (W-9, W-8, etc.) with client org email
- Upgraded to PHP 8.3 with updated dependencies (Twig 3.21, Monolog 3.0)
- Fixed recurring billing and on-demand document logic
- Resolved multi-signature function issues on contracts
- Added project and client filtering improvements
- Integrated Twig templating for consistent list views
- Fixed invoice public view and Stripe connection issues
- Moved document settings to consolidated Documents tab with sub-tabs
- Redesigned Financial navigation and unified Expenses Hub
- Improved financial dashboard spacing, readability, and responsive layout

### Security & Infrastructure (2026)

- **Removed committed encryption key** — `APP_ENCRYPTION_KEY` now env-var only; `config/settings.json` is untracked (burned old key)
- **Moved Stripe secrets to `.env`** — no longer stored in committed `settings.json`; encrypted before DB storage
- **Docker Compose hardening** — all passwords from `.env` with `:?err` validation; DB port removed from host mapping (internal network only)
- **Router-level audit middleware** — logs all sensitive actions (payments, 2FA, API keys, deletes, contract sign, email, PDF export, webhooks)
- **Password policy enforcement** — 8+ chars, mixed case, digit, special char on register/reset/update
- **Login rate limiting** — IP (15/10min) and per-account (5/15min) lockouts
- **Two-Factor Authentication (TOTP)** — optional 2FA with backup codes
- **InnoDB encryption at rest** — MySQL 8.4 `component_keyring_file`; all 61 tablespaces encrypted
- **Apache hardening** — `ServerTokens Prod`, `ServerSignature Off`, `expose_php=Off`, security headers, CSP
- **ZAP baseline scan** — 0 failures, 9 low-sev warnings (informational only); report in `docs/zap_baseline_2026-06-11.html`
- **Backup & restore tooling** — `tools/db_backup.sh` and `tools/db_restore.sh` with 7-day rotation
- **Auth folder migration** — controllers and views moved to `src/controllers/auth/` and `src/views/pages/auth/` for consistency

---

*For detailed technical documentation, see the `docs/` folder including:*
- `AGENTS.md` - Development guidance for AI assistants
- `AUTH_MIGRATION.md` - Auth folder reorganization summary
- `ENCRYPTION_AT_REST.md` - InnoDB tablespace encryption setup & gotchas
- `IMPLEMENTATION_ORDER.md` - Feature implementation sequence
- `PROGRESS_SUMMARY.md` - Completed tasks and status
- `RECURRING_INVOICES_SETUP.md` - Recurring billing guide
- `ON_DEMAND_CONTRACTS_README.md` - On-demand features
- `FILTER_MIGRATION_SUMMARY.md` - Template migration notes
- `SECURITY.md` - Security contact info
- `zap_baseline_2026-06-11.html` - OWASP ZAP baseline scan report

## CI/CD and Security

This repo uses only free GitHub-native tools — no paid licenses required.

### Workflows
- **CI** (`.github/workflows/ci.yml`) — builds Docker Compose, runs health check, PHP syntax check, PHPUnit, composer audit
- **Docker** (`.github/workflows/docker-publish.yml`) — builds and pushes images to ghcr.io on push to dev/main
- **CodeQL** (`.github/workflows/codeql.yml`) — free code-level vulnerability scanning (JS + Python)

### Required GitHub repo settings (one-time, manual)
Go to **Settings** in the GitHub repo:
1. **Security > Code security and secret scanning > Enable** — turn on:
   - Secret scanning (free for public repos)
   - Push protection (free for public repos)
   - Dependency alerts (Dependabot)
2. **Settings > Actions > General** — ensure workflows have read/write permissions
3. **Settings > Packages** — make `ghcr.io/ledgetoptechnologies/project-alpha` package public if you want end users to pull without authentication

### What was removed (paid licenses)
- ~~Gitleaks~~ — replaced by GitHub built-in secret scanning + push protection (free)
- ~~Docker Hub~~ — replaced by ghcr.io (free for public repos)
- ~~Nightly Docker Hub build~~ — removed (dead code)

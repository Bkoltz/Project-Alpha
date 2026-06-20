# AGENTS.md

This file provides guidance for AI agents (Hermes, WARP, Claude Code, etc.) when working with code in this repository.

## Project Overview

Project Alpha is a PHP 8.3 application for managing clients, quotes, contracts, invoices, expenses, and payments. It runs on Docker with Apache and MySQL 8. The dev branch uses a **unified schema** — see `CONTEXT.md` for schema rules.

## Build & Run Commands

```bash
# Build and start containers
docker compose build web
docker compose up -d

# App at http://localhost:1627
# Log in with admin@project-alpha.local / <ADMIN_PASSWORD from compose>

# Run tests
vendor/bin/phpunit --colors=always

# Deploy CSS changes (styles.css is baked into image, not bind-mounted)
docker cp public/assets/styles.css project-alpha-web-1:/var/www/html/assets/styles.css
```

## Critical Rules (READ BEFORE MAKING CHANGES)

1. **Schema**: `quotes` table uses `quote_type` column (NOT `is_long_term`). `contracts` table uses `contract_type` (NOT separate `long_term_contracts`/`on_demand_contracts` tables). `invoices` uses `invoice_type` + `contract_id` (NOT `on_demand_contract_id`).
2. **CSS**: List pages and create/edit pages use **main branch inline CSS styling** (not CSS classes like pa-table, btn, alert). Dev-only pages (expenses, financial dashboard, legal, settings) use CSS classes from the appended section of `styles.css`.
3. **JS**: All JS files are in `public/assets/js/` (NOT `public/js/`). Templates reference `/assets/js/*.js`. JS files are dev versions — supersets of main's functions. Do NOT restore main's JS files.
4. **Cross-branch restoration**: When restoring files from `main` to `dev`, you MUST patch SQL WHERE/SELECT/JOIN clauses for the unified schema. Main uses old schema columns. See `CONTEXT.md` for details.
5. **Dashboard**: `home.php` is the dev redesigned 466-line version with SVG charts. Do NOT replace with main's 77-line version.

## Architecture

### Entry Point & Routing

All requests go through `public/index.php`. Routing is handled via the `page` query parameter (e.g., `/?page=clients-list`). The router supports:
- Direct page names: `clients-list` → `src/views/pages/clients-list.php`
- Namespaced pages: `client/clients-edit` → `src/views/pages/client/clients-edit.php`
- Case-insensitive folder resolution

POST requests are routed to controllers in `src/controllers/`. AJAX requests return only the main content without layout.

### Directory Structure

```
public/
├── index.php              # Single entry point, routing logic
├── assets/
│   ├── styles.css         # Main's 113-line CSS + appended dev-only classes
│   ├── js/                # All page-logic JS (consolidated, dev supersets)
│   ├── navigation.js      # SPA-like nav handler
│   └── item-autocomplete.js
src/
  config/                  # Database connection (db.php), bootstrap, app config
  controllers/             # POST handlers and API endpoints, organized by domain
    auth/                  # Login, 2FA, password reset, account management
    contract/              # Contract CRUD + LT/OD lifecycle actions
    invoice/               # Invoice CRUD
    financial/             # Expense, audit, CSV import, category/vendor handlers
    stripe/                # Stripe checkout, charge, success
    webhook/               # Stripe webhook receivers
    public_view/           # Public document views and actions
    settings/              # Settings sub-handlers (links, tax, custom fields, backup)
  services/                # Business logic (StripeService, LinkResolverService)
  utils/                   # Shared utilities (see below)
  views/
    pages/                 # Page templates organized by domain
    partials/              # Shared layout (header with mobile topbar, footer with ToS links)
    templates/             # Twig templates and components
  cron/                    # Scheduled task scripts
  uploads/                 # User uploads (mounted as Docker volume)
database/
  init.sql                 # Unified schema with all modules + custom field seed data
  migrations/              # Legacy migration files
work_flow/                 # Business logic documentation
docs/                      # Technical documentation
tools/                     # Backup/restore scripts, audit generator
```

### Domain Model

**Document Hierarchy:**
- Quote (`draft` → `pending` → `approved`/`denied`) — When approved, auto-generates Contract + Invoice
- Contract (`pending` → `active` → `complete`) — Stores signed PDF uploads
- Invoice (`unpaid` → `partial` → `paid`)

**Document Types** (stored in `quote_type`/`contract_type`/`invoice_type` columns):
- `regular` — One-time documents
- `long_term` — Recurring billing with intervals
- `on_demand` — Flexible manual invoicing

**Entity Relationships:**
- Organizations contain Clients
- Clients have Quotes, Contracts, Invoices
- Projects group related documents via `project_code`

### Key Patterns

**Database Access:** Direct PDO with `$pdo` global (initialized in `src/config/db.php`).

**Authentication:** Session-based auth with CSRF (Symfony token + legacy fallback). Optional 2FA (TOTP). Rate limiting (IP + per-account). Password policy enforcement.

**PDF Generation:** Uses Dompdf. Controllers set `PDF_MODE` constant, include the view template, then stream PDF output.

**Templates:** Mix of plain PHP views (list/create pages with inline styles) and Twig components (document-filter, list-view).

**Custom Fields:** `document_custom_fields` table stores configurable fields per document type. Seeded with `deposit` (Deposit Required) and `fulfillment_date` (Fulfillment Date Estimated) for all 3 types. Rendered by `renderDocumentCustomFields()` in `src/utils/document_fields.php`.

### Key Utilities

- `crypto.php` — AES-256-GCM encrypt/decrypt
- `csrf.php` / `csrf_sf.php` — CSRF tokens (legacy + Symfony)
- `client_ip.php` — Real client IP detection behind reverse proxy (TRUSTED_PROXIES)
- `upload_validator.php` — File upload validation (real MIME via finfo)
- `audit.php` / `audit_middleware.php` — Audit logging
- `password_policy.php` — Password strength enforcement
- `rate_limiter.php` — Sliding-window rate limiting
- `security_headers.php` — CSP, HSTS, frame options
- `StripeFeeCalculator.php` / `InvoiceSurcharge.php` — Stripe fee calculation

## Environment Variables

Only 3 passwords need setting in `docker-compose.yml`:
- `MYSQL_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `ADMIN_PASSWORD`

`TRUSTED_PROXIES` is optional (for reverse proxy IP detection).
`APP_ENCRYPTION_KEY` is auto-generated by `docker/start.sh`.
Stripe keys entered via Settings > Billing UI.

## Workflow Documentation

Detailed business logic documentation is in `work_flow/`:
- `document_types.md` — Quote/Contract/Invoice lifecycles
- `projects.md` — Jobs (project_code) vs Projects distinction
- `long-term_docs.md` / `regular_docs.md` — Document generation patterns
- `cron.md` — Scheduled tasks

## Full Context

See `CONTEXT.md` in the project root for complete schema rules, CSS/styling rules, deployment notes, and the cross-branch restoration warning.

# AGENTS.md

This file provides guidance to WARP (warp.dev) when working with code in this repository.

## Project Overview

Project Alpha is a PHP 8.3 application for managing clients, quotes, contracts, and invoices. It runs on Docker with Apache and MySQL 8.

## Build & Run Commands

```powershell
# Build and start containers
docker compose build --no-cache
docker compose up -d

# Run tests
composer test
# Or directly:
vendor/bin/phpunit --colors=always

# Local dev server (without Docker)
composer install
composer start   # Starts PHP built-in server on port 8080
```

The app is accessible at `http://localhost:1627` when running via Docker Compose (port 80 inside container).

## Architecture

### Entry Point & Routing

All requests go through `public/index.php`. Routing is handled via the `page` query parameter (e.g., `/?page=clients-list`). The router supports:
- Direct page names: `clients-list` → `src/views/pages/clients-list.php`
- Namespaced pages: `client/clients-edit` → `src/views/pages/client/clients-edit.php`
- Case-insensitive folder resolution (tries `jobs/`, `Jobs/`, etc.)

POST requests are routed to controllers in `src/controllers/`. AJAX requests (detected via `X-Requested-With` header) return only the main content without layout.

### Directory Structure

```
public/index.php     - Single entry point, routing logic
src/
  config/            - Database connection (db.php), bootstrap (creates tables)
  controllers/       - POST handlers and API endpoints, organized by domain
  services/          - Business logic (LinkResolverService, StripeService)
  utils/             - Shared utilities (csrf, crypto, mailer, logger, twig)
  views/
    pages/           - Page templates organized by domain (client/, contract/, invoice/, etc.)
    partials/        - Shared layout components (header, footer)
  cron/              - Scheduled task scripts
  uploads/           - User uploads (mounted as Docker volume)
config/              - Runtime config (settings.json, environment.env)
database/migrations/ - SQL schema files
work_flow/           - Business logic documentation
```

### Domain Model

**Document Hierarchy:**
- Quote (`draft` → `pending` → `approved`/`denied`) — When approved, auto-generates Contract + Invoice
- Contract (`pending` → `active` → `complete`) — Stores signed PDF uploads
- Invoice (`unpaid` → `partial` → `paid`)

**Entity Relationships:**
- Organizations contain Clients
- Clients have Quotes, Contracts, Invoices
- **Jobs** (`project_code`) — Auto-generated codes that group related documents
- **Projects** — Manual parent entities for organizing Jobs across larger initiatives

### Key Patterns

**Database Access:** Direct PDO with `$pdo` global (initialized in `src/config/db.php`). Environment variables: `DB_HOST`, `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD`.

**Authentication:** Session-based auth. Disable with `APP_AUTH_DISABLED=true` env var. Public pages defined in `$publicPages` array in `public/index.php`.

**CSRF:** Uses `csrf_init()` / `csrf_verify_post_or_redirect()` from `src/utils/csrf.php`. Some endpoints skip CSRF (defined in `$skipCsrfFor`).

**PDF Generation:** Uses Dompdf. Controllers (`contract_pdf.php`, `quote_pdf.php`, `invoice_pdf.php`) set `PDF_MODE` constant, include the view template, then stream PDF output.

**Templates:** Mix of plain PHP views and Twig (`src/utils/twig.php` for configuration).

## Testing

No `phpunit.xml` exists — tests run with default PHPUnit configuration. Test files should be placed in a `tests/` directory following PSR-4 autoloading under `App\` namespace.

## Environment Variables

| Variable | Purpose |
|----------|---------|
| `DB_HOST` | MySQL host (default: `db`) |
| `MYSQL_DATABASE` | Database name (default: `project_alpha`) |
| `MYSQL_USER` / `MYSQL_PASSWORD` | DB credentials |
| `APP_AUTH_DISABLED` | Set `true` to bypass authentication |
| `APP_API_ENABLED` | Enable/disable API endpoints (default: `true`) |

## Workflow Documentation

Detailed business logic documentation is in `work_flow/`:
- `document_types.md` — Quote/Contract/Invoice lifecycles
- `projects.md` — Jobs (project_code) vs Projects distinction
- `long-term_docs.md` / `regular_docs.md` — Document generation patterns
- `cron.md` — Scheduled tasks

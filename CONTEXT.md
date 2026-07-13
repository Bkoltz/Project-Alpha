# Project Alpha Context

Last reviewed: 2026-06-28

Project Alpha is a self-hosted business operations application for quotes, contracts, invoices, payments, expenses, receipts, mileage, and supporting records. It is currently used internally by Ledge Top Technologies LLC and Ledge Top Drone Services.

## Runtime

- Production image: PHP 8.5 with Apache
- Composer platform: PHP 8.3.31; package constraint PHP 8.1+
- Database: MySQL 8
- Templates: PHP views plus Twig components
- Frontend: server-rendered HTML, CSS, and vanilla JavaScript
- Payments: Stripe Checkout and Payment Intents
- Deployment: Docker Compose with web, cron, and database services

## Commands

Published images:

```bash
docker compose pull
docker compose up -d
```

Build the current checkout:

```bash
docker build --target test -t ghcr.io/ledgetoptechnologies/project-alpha:latest .
docker build --target cron -t ghcr.io/ledgetoptechnologies/project-alpha:cron-latest .
docker compose up -d
```

Tests and migration validation:

```bash
composer test
docker compose run --rm migrate \
  php /var/www/src/migrations/run_migrations.php --dry-run --verbose
```

## Sources of Truth

Use these in order when documentation conflicts:

1. Current application code and Docker files
2. `database/baseline.sql` for fresh installs
3. Active SQL files in `database/migrations/` for upgrades
4. `docs/DOCUMENT_WORKFLOW.md` for user-facing document behavior
5. Current operating guides listed in `docs/README.md`

Point-in-time plans and audits in `docs/` are historical records, not current instructions.

## Architecture

- `public/index.php`: entry point, authentication gates, route dispatch, and middleware
- `public/assets/`: stylesheets and browser assets
- `public/js/`: page-specific browser logic
- `src/config/`: application and database bootstrap
- `src/controllers/`: request handlers grouped by domain
- `src/services/`: payment, email, and link services
- `src/utils/`: shared security, billing, logging, and rendering utilities
- `src/views/pages/`: PHP page views
- `src/views/templates/`: Twig components and layouts
- `src/cron/`: scheduled job scripts
- `database/baseline.sql`: immutable 0.5.0 schema for a fresh database
- `database/migrations/`: immutable, sequential upgrades after the baseline
- `cron/`: cron image entrypoint and installed schedule

## Data Model

The current schema uses one table per primary document kind and type columns for workflow families:

- `quotes.quote_type`: `regular`, `long_term`, `on_demand`
- `contracts.contract_type`: `regular`, `long_term`, `on_demand`
- `invoices.invoice_type`: `regular`, `long_term`, `on_demand`

Do not introduce separate long-term or on-demand document tables.

Primary relationships:

- Clients may belong to organizations.
- Projects are manually managed parent records.
- `project_code` is a job code propagated across related documents.
- Quotes may generate contracts and invoices.
- Contracts own scheduled or manually generated invoices.
- Payments belong to invoices.

## Workflow Rules

- A pending regular quote may create a pending contract and unpaid invoice on approval.
- Long-term and on-demand quote approval creates the matching contract; invoices follow contract activation.
- Uploading a signed regular contract activates it.
- Long-term and on-demand contracts require an explicit activation step after a signed document exists.
- Long-term invoices use `next_invoice_date` and billing interval fields.
- On-demand invoices are generated manually from an active contract.
- Completing a contract applies net terms to the newest related invoice when its due date is empty.
- Voiding a contract voids related invoices and revokes their public links.
- Recording full payment marks an invoice paid and may complete the linked contract.

See `docs/DOCUMENT_WORKFLOW.md` before changing these behaviors.

## Persistent Data and Secrets

Named volumes hold uploads, application configuration, backups, and database data. The application encryption key is generated on first boot when not supplied and stored in the configuration volume.

Never commit:

- Application or database passwords
- Stripe, SMTP, OAuth, or API credentials
- The generated encryption key
- Customer records, uploaded documents, or production logs
- Production hostnames, private addresses, or access instructions that are not intended to be public

Back up the configuration and backup volumes as well as MySQL. Encrypted values cannot be recovered without the original application encryption key.

## Branch and Image Flow

- `dev` publishes `:dev` for web and `:cron` for cron.
- `main` publishes `:latest` for web and `:cron-latest` for cron.
- `main` is protected and changes enter through pull requests.
- Production and staging deployments use different databases, named volumes, and credentials.

## Documentation

- `README.md`: project overview and installation
- `CONTRIBUTING.md`: issues, branches, pull requests, and validation
- `docs/README.md`: documentation index
- `docs/AGENTS.md`: coding-agent and contributor rules
- `docs/DOCUMENT_WORKFLOW.md`: authoritative document workflow
- `database/migrations/README.md`: schema migration rules
- `cron/README.md`: installed scheduled jobs

## Contact

- Owner: Beau Koltz
- Company: Ledge Top Technologies LLC
- Security reports: see `docs/SECURITY.md`

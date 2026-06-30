# Project Alpha

Project Alpha is a self-hosted business operations application for managing clients, quotes, contracts, invoices, payments, expenses, receipts, mileage, and supporting documents.

It is developed by [Ledge Top Technologies LLC](https://ledgetoptechnologies.com/) and is currently used in production by Ledge Top Technologies and Ledge Top Drone Services. The project is stable enough for day-to-day internal use, but it is still evolving; bugs and feature requests are tracked publicly in [GitHub Issues](https://github.com/ledgetoptechnologies/Project-Alpha/issues).

> Project Alpha is business software, not accounting, tax, or legal advice. Review your own operational and regulatory requirements before relying on it for third-party hosting.

## Highlights

- Regular, long-term, and on-demand quotes and contracts
- Quote-to-contract and contract-to-invoice workflows
- Scheduled recurring invoices with downtime catch-up
- Manual on-demand invoice generation
- Fixed-price and hourly billing
- Stripe Checkout, Payment Intents, webhooks, and reconciliation
- Offline payment recording and partial-payment tracking
- Tokenized public links for document review, signing, and payment
- Clients, organizations, projects, and automatically generated job codes
- Item library, discounts, deposits, taxes, and custom document fields
- Expenses, receipts, vendors, mileage, forms, and financial reporting
- Role-based permissions, per-user overrides, optional TOTP 2FA, and audit logs
- Docker Compose deployment with separate web, cron, and MySQL services
- Prebuilt images published through GitHub Container Registry

## Document Workflow

Project Alpha supports three primary document families:

| Family | Intended use | Invoice behavior |
|---|---|---|
| Regular | One-time work | A regular invoice is normally created with the contract |
| Long-term | Recurring services or retainers | Invoices are generated from the active contract schedule |
| On-demand | Work billed only when needed | Invoices are generated manually from the active contract |

The usual regular workflow is:

```text
Quote -> Contract -> Invoice -> Payment
```

Long-term and on-demand workflows branch after contract activation. See [Document Workflow](docs/DOCUMENT_WORKFLOW.md) for statuses, automatic actions, public-link behavior, and examples.

## Current Scope

Project Alpha currently targets a self-hosted deployment operated for one business. The schema includes organizations and organization-aware permissions, but the application is not yet offered as a fully isolated, hosted multi-tenant service.

Current boundaries include:

- Public tokenized document links instead of persistent client accounts
- Responsive web UI, but no native mobile application or installable PWA
- Operational income and expense reporting, but not a complete general ledger
- Stripe is implemented; other payment processors are not yet available
- Deployment, backups, email, and payment credentials remain the operator's responsibility

## Quick Start

### Requirements

- Docker Engine with Docker Compose v2
- A host that can pull public images from `ghcr.io`
- Ports and reverse-proxy configuration appropriate for your environment

### Start the published images

1. Clone the repository:

   ```bash
   git clone https://github.com/ledgetoptechnologies/Project-Alpha.git
   cd Project-Alpha
   ```

2. In `docker-compose.yml`, replace all three `changeme` passwords:

   - `ADMIN_PASSWORD`
   - `MYSQL_PASSWORD`
   - `MYSQL_ROOT_PASSWORD`

3. Pull and start the stack:

   ```bash
   docker compose pull
   docker compose up -d
   ```

4. Open <http://localhost:1627> and sign in with:

   - Email: `admin@project-alpha.local`
   - Password: the `ADMIN_PASSWORD` value from your Compose configuration

5. Complete the application settings:

   - **System**: organization identity, application URL, timezone, and sender profile
   - **Billing**: Stripe keys, net terms, payment methods, and surcharge preferences
   - **Email**: SMTP connection and sender information
   - **Documents**: terms, custom fields, automatic document creation, and link lifetime
   - **Notifications**: cron and invoice-email options

The one-shot `migrate` service initializes and validates the database before web or cron can start. Project Alpha 0.5.0 is a destructive database reset and cannot upgrade a 0.4.x database in place; follow the [0.5.0 reset runbook](docs/0.5.0-database-reset.md).

### Staging

`docker-compose.staging.yml` runs the `dev` images on port `1628` with separate named volumes. Use different passwords from production.

```bash
docker compose -f docker-compose.staging.yml pull
docker compose -f docker-compose.staging.yml up -d
```

## Local Development

The default Compose file pulls published images. Add the override to build the current checkout:

```bash
docker compose -f docker-compose.yml -f docker-compose.override.yml up -d --build
```

Useful checks:

```bash
composer install
composer test
docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm migrate \
  php /var/www/src/migrations/run_migrations.php --dry-run --verbose
```

The production images use PHP 8.5. Composer currently targets PHP 8.3.31 for dependency resolution and permits PHP 8.1 or newer.

## Architecture

| Layer | Implementation |
|---|---|
| Web runtime | PHP 8.5 with Apache |
| Application | Custom PHP routing and controllers; no full-stack framework |
| Database | MySQL 8 with PDO |
| Templates | PHP views plus Twig components |
| Frontend | Server-rendered HTML, CSS, and vanilla JavaScript |
| PDFs | Dompdf |
| Payments | Stripe Checkout, Payment Intents, webhooks, and reconciliation |
| Scheduling | Dedicated cron container |
| Deployment | Multi-stage Docker image and Docker Compose |

All HTTP requests enter through `public/index.php`. Application source is under `src/`, the immutable fresh-install schema is `database/baseline.sql`, and sequential forward migrations live under `database/migrations/`.

## Scheduled Operations

The cron image runs recurring invoice generation, backups, contract expiration, public-link expiration, audit schedules, invoice reminders, Stripe reconciliation, and merchant-rate synchronization. AutoPay is an unavailable beta foundation and is not scheduled or exposed.

See [Cron Service](cron/README.md) for the installed schedule and [Recurring Invoices](docs/RECURRING_INVOICES_SETUP.md) for billing behavior.

## Security and Operations

Project Alpha includes application-level controls such as CSRF validation, prepared database access, rate limiting, optional TOTP 2FA, role-based authorization, encrypted storage for configured secrets, audit logging, security headers, and webhook signature validation when a Stripe webhook secret is configured.

Operators remain responsible for:

- HTTPS and reverse-proxy configuration
- Unique passwords and credential rotation
- Protecting the persistent encryption key and backup volume
- Restricting network access to MySQL
- Testing migrations and restoring backups
- Keeping container images and dependencies current
- Reviewing application logs without committing credentials or customer data

Report security vulnerabilities privately as described in [Security Policy](docs/SECURITY.md).

## Repository Workflow

- Report reproducible bugs through [GitHub Issues](https://github.com/ledgetoptechnologies/Project-Alpha/issues).
- Never include credentials, payment data, or identifiable customer information in an issue.
- `main` is protected and requires a pull request with passing checks.
- `dev` publishes the staging images; `main` publishes the production images.
- Testing should be proportional to the change, with browser verification for user-facing workflows.

See [Contributing](CONTRIBUTING.md) for issue and pull-request guidance.

## Documentation

Start with the [Documentation Index](docs/README.md).

Key guides:

- [Document Workflow](docs/DOCUMENT_WORKFLOW.md)
- [TrueNAS Scale Deployment](docs/truenas-scale-deployment.md)
- [Stripe Webhook Setup](docs/stripe-webhook-setup.md)
- [Migration Safety](docs/MIGRATION_SAFETY.md)
- [Cron Service](cron/README.md)
- [Database Migrations](database/migrations/README.md)
- [Developer and Agent Guidance](docs/AGENTS.md)

## License

The repository currently contains a legacy Project Alpha license in [LICENSE.md](LICENSE.md). A transition to GNU AGPLv3 is planned after contributor relicensing permission is documented. Until the license file is formally replaced, its current terms control use of the project.

Copyright Ledge Top Technologies LLC.

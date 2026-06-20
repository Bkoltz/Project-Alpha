# Project Alpha — Context

Last updated: 2026-06-19 by Hermes

## What This Is
PHP 8.3 business document management system for quotes, contracts, invoices, receipts, and expenses with Stripe payment integration. Built for organizations requiring automated billing, secure document handling, and comprehensive financial reporting.

## Quick Start
```bash
cd /home/bkoltz/Project-Alpha
docker compose up -d --build    # Start containers
# App at http://localhost:1627
# Log in with admin@project-alpha.local / <ADMIN_PASSWORD from compose file>
# Enter Stripe keys in Settings > Billing (stored encrypted in the DB)
```
Docker: Apache + MySQL 8 | Port: 1627

## Architecture
- PHP 8.3, no framework (custom routing via `page` query param)
- MySQL 8 with direct PDO ($pdo global)
- Docker Compose (Apache + MySQL + cron container)
- Stripe Payment Intents (not Stripe Invoices)
- Dompdf for PDF generation
- Session-based auth (can be disabled via APP_AUTH_DISABLED=true)
- Twig templates mixed with plain PHP views

### Directory Structure
```
public/index.php     - Single entry point, routing
src/config/          - DB connection, bootstrap
src/controllers/      - POST handlers, API endpoints (by domain)
src/services/         - Business logic (LinkResolver, StripeService)
src/utils/            - CSRF, crypto, mailer, logger, twig
src/views/pages/      - Page templates (by domain)
src/views/partials/   - Shared layout (header, footer)
cron/                 - Scheduled task scripts (daily at 2am)
work_flow/            - Business logic documentation
docs/                 - Technical documentation + AGENTS.md
api/                  - API data files (income-data.json)
```

## Current State
- Core features working: quotes, contracts, invoices, payments, receipts, expenses, mileage, vendors, categories
- Stripe webhook handling live
- Recurring invoice generation for long-term contracts
- Auto-termination of expired contracts
- Cron runs daily at 2:00am for automated tasks
- No .env file required — all defaults live in docker-compose.yml

## Recent Changes
- 2026-06-19: Security audit — .env locked to 600, api_keys_*.php controllers set to 640
- 2026-06-19: Removed docker-compose.example.yml, docker-compose.staging.yml, and staging-specific docs/workflows. Single self-contained docker-compose.yml.
- 2026-06-17: Recent development session (see skill references for details)

## Decisions
- Session-based auth (not JWT) — simpler for this use case
- Direct PDO instead of ORM — performance + simplicity
- Stripe Payment Intents (not Stripe Invoices) — more flexible
- APP_AUTH_DISABLED defaults to false; set to true only for local dev convenience
- No .env file required: docker-compose.yml uses inline defaults; encryption key auto-generated on first run

## Known Issues
- See docs/TODO.md for open items

## Credentials & Access
- All defaults are in `docker-compose.yml` (edit passwords before deployment)
- Stripe keys: entered via Settings > Billing UI and stored encrypted in the `app_config` DB table
- DB: MySQL in Docker container (project_alpha database)

## Contact
Owner: Beau Koltz
Company: Ledge Top Technologies LLC

## Subfolder Context Files
- `api/CONTEXT.md` — API endpoints and data shapes
- `src/controllers/CONTEXT.md` — Controller routing and business logic
- `cron/CONTEXT.md` — Scheduled tasks reference
- `work_flow/` — Business workflow documentation (already exists)
- `docs/AGENTS.md` — AI agent guidance (already exists)

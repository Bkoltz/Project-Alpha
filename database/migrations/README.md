# Project Alpha - Database Schema

## Overview

The active schema is split into 8 ordered module files and is also concatenated into `database/init.sql`, which Docker uses as the single source of truth for fresh databases.

## Active Module Files

| # | File | Description |
|---|------|-------------|
| 001 | `001_auth_module.sql` | Users, password resets, login attempts, 2FA, trusted devices/IPs |
| 002 | `002_organizations_module.sql` | Organizations, memberships, API keys, API usage, webhooks |
| 003 | `003_projects_clients_module.sql` | Clients, projects, project metadata, counters, project document links, entity links |
| 004 | `004_documents_module.sql` | Separate `quotes`, `contracts`, `invoices`, and `recurring_invoices` tables |
| 005 | `005_financial_module.sql` | Payments, auto-pay logs, payment methods, tax rates, item library, receipts, forms, discounts, financial records |
| 006 | `006_audit_system_module.sql` | System audit, audit schedules, notifications, cron runs, app config |
| 007 | `007_public_links_module.sql` | Public document links, link resolver config, document customization, archived entities |
| 008 | `008_seed_data.sql` | Default organization, admin user, app config |

## Document Model

Project Alpha intentionally uses separate tables for the three primary document families:

- `quotes` with `quote_type ENUM('regular','long_term','on_demand')`
- `contracts` with `contract_type ENUM('regular','long_term','on_demand')`
- `invoices` with `invoice_type ENUM('regular','long_term','on_demand')`

This keeps the most common queries and foreign keys straightforward while still allowing each document table to support regular, long-term, and on-demand workflows.

## Public Links

Public links use `document_type` and `document_id`. These names match `project_documents` and make the polymorphic relationship explicit.

## Deprecated Files

The `deprecated/` folder contains old migration files kept for reference only. Do not run those files against a fresh database.

## Rebuild

```bash
docker compose down -v
docker compose up --build
```

Fresh Docker databases execute `database/init.sql` via MySQL's `/docker-entrypoint-initdb.d`.

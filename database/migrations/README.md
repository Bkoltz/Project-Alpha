# Project Alpha - Database Schema

## Overview

The database schema is organized into **8 modular files** that are loaded sequentially by `init.sql`. This modular approach makes the schema easier to maintain, understand, and extend.

## Module Files

| # | File | Description | Tables |
|---|------|-------------|--------|
| 001 | `001_auth_module.sql` | Authentication & Identity | users, password_resets, login_attempts, login_2fa_attempts, user_2fa, trusted_devices, trusted_ips |
| 002 | `002_organizations_module.sql` | Organizations, API Keys & Webhooks | organizations, user_organizations, api_keys, api_usage, webhooks, webhook_deliveries |
| 003 | `003_projects_clients_module.sql` | Projects & Clients | clients, projects, project_meta, project_counters, project_documents |
| 004 | `004_documents_module.sql` | Consolidated Documents | documents, document_items, document_signatures, document_notes, document_history, invoice_notifications |
| 005 | `005_financial_module.sql` | Financial | payments, payment_methods, tax_rates, item_library, receipt_stores, receipts, form_categories, form_documents |
| 006 | `006_audit_system_module.sql` | Audit, Notifications & System | system_audit, audit_schedules, audit_schedule_logs, notification_settings, notification_log, notifications, cron_job_runs, app_config |
| 007 | `007_public_links_module.sql` | Public Links & Customization | public_links, link_resolver_config, document_custom_fields, document_settings, document_custom_field_values |
| 008 | `008_seed_data.sql` | Seed Data | Default org, admin user, app config |

## Key Design Decisions

### Consolidated Documents Table
Instead of separate `quotes`, `contracts`, `invoices`, and `recurring_invoices` tables, we now have a single `documents` table with a `document_type` enum:
- `'quote'` - Initial proposals
- `'contract'` - Active agreements (includes long_term and on_demand via `contract_type`)
- `'invoice'` - Single invoices
- `'recurring_invoice'` - Recurring invoice instances

This reduces duplication significantly since all document types share common fields (client_id, project_id, subtotal, total, status, etc.)

### Unified Items Table
The `document_items` table replaces `quote_items`, `contract_items`, and `invoice_items` with a single foreign key to `documents(id)`.

### Status Flexibility
Each document type uses different status values stored in a `VARCHAR(50)` column rather than separate enums. Application-level validation ensures valid statuses per type:
- **quote**: draft, pending, approved, denied, expired
- **contract**: draft, pending, active, paused, completed, cancelled, denied, void
- **invoice**: draft, sent, unpaid, partial, paid, overdue, cancelled, void
- **recurring_invoice**: draft, sent, paid, overdue, cancelled, void

## Deprecated Files

The `deprecated/` folder contains old migration files (001-014) that are kept for historical reference only. They are no longer executed.

The `000_all_DEPRECATED.sql` file contains the original monolithic schema and is also kept for reference.

## How to Rebuild

```bash
docker compose down -v
docker compose up --build
```

This will:
1. Drop the existing database volume
2. Rebuild containers
3. Execute `init.sql` which sources all module files in order
4. Seed default data

## Adding New Tables

1. Choose the appropriate module file (or create a new one if needed)
2. Add your `CREATE TABLE` statement
3. If creating a new module, update `init.sql` to source it
4. Rebuild with `docker compose down -v && docker compose up --build`

## Notes

- All tables use `IF NOT EXISTS` for idempotency
- Foreign keys reference unified `documents` table instead of separate quote/contract/invoice tables
- Application code will need updates to use the new unified schema
- Legacy tables (long_term_contracts, on_demand_contracts, archived_clients, etc.) have been removed as their functionality is covered by the unified schema

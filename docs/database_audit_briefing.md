# PROJECT ALPHA - FULL DATABASE AUDIT & REFACTOR BRIEFING
## Generated: 2026-06-16
## Branch: dev (pulled latest)
## Commit: d6b8d76 (Merge dev)

---

## EXECUTIVE SUMMARY

Project Alpha is a PHP-based SaaS for quotes/contracts/invoices/receipts with Stripe integration.
257 PHP files. No ORM — raw PDO everywhere. Database managed via init.sql + numbered migrations.
Major issues: schema/code drift, dead tables, duplicated cron code, migration chaos.

---

## 1. CRITICAL SCHEMA-TO-CODE INCONSISTENCIES (WILL BREAK THINGS)

### A. `project_documents.document_type` ENUM MISMATCH
**init.sql**: `ENUM('quote','contract','invoice','recurring_invoice','receipt','form','other')`
**migration 011**: `ENUM('quote','contract','invoice','receipt','form','other')` — MISSING `'recurring_invoice'`
- **Impact**: `project_add_document.php` line 22 maps `recurring_invoice => recurring_invoices` — insert will fail on migration-fresh DB
- **Files**: `src/controllers/project/project_add_document.php`, `project_remove_document.php`

### B. `projects.status` ENUM MISMATCH  
**init.sql**: `'not_started','active','overdue','completed','cancelled'`
**migration 011**: `'active','completed','on_hold','cancelled'` — missing `'not_started'`, `'overdue'`; has `'on_hold'`
- **Impact**: Any code inserting 'not_started' or 'overdue' will fail on migration-fresh DB

### C. `clients` table — MIGRATION 011 MISSING CRITICAL COLUMNS
Missing in migration vs init.sql: `auto_pay_enabled`, `config`, `stripe_customer_id`, `stripe_payment_method_id`
- **Impact**: `auto_charge_recurring.php` SELECTs `c.auto_pay_enabled`, `c.stripe_customer_id`, `c.stripe_payment_method_id` — these don't exist in migration 011
- **Files**: `src/cron/auto_charge_recurring.php` (and all Stripe webhook handlers that read client payment data)

### D. `payments` table — MIGRATION 010 MISSING CRITICAL COLUMNS
Missing in migration vs init.sql: `surcharge_paid`, `stripe_session_id`, `stripe_payment_intent_id`, `auto_pay_attempt`, `status`
- **Impact**: Almost every payment INSERT across the codebase uses these columns
- **Files affected**:
  - `src/controllers/stripe/stripe_webhook.php` lines 205, 288
  - `src/controllers/invoice/invoices_mark_paid.php` line 28
  - `src/controllers/payments_create.php` line 44
  - `src/controllers/contract/contract_deposit_received.php` line 52
  - `src/controllers/webhook/stripe_payment_failed.php` line 23
  - `src/controllers/webhook/stripe_checkout_completed.php` line 50
  - `src/controllers/webhook/stripe_payment_succeeded.php` line 64
  - `src/cron/auto_charge_recurring.php` lines 100-110

### E. `document_custom_fields` — MIGRATION 011 MISSING 8 COLUMNS
Missing: `field_data_type`, `field_key`, `field_label`, `default_value`, `min_value`, `max_value`, `is_builtin`, `is_enabled`
- Also `field_name` changed from NULLable to NOT NULL in migration
- **Impact**: Controllers/views that reference these columns (e.g., `src/views/pages/settings/documents/customization.php`, `document-customization-save.php`)

### F. `link_resolver_config` — MIGRATION 011 MISSING 3 COLUMNS
Missing: `credentials`, `default_expiration_days`, `is_enabled`
- **Impact**: `src/services/LinkResolverService.php`, `dropbox_oauth.php`, `links_handler.php` reference these columns

### G. `payment_methods` — MIGRATION 010 MISSING 3 COLUMNS
Missing: `config`, `is_active`, `provider`
- **Impact**: Controllers/views that reference these

---

## 2. ORPHAN TABLES (ZERO CODE REFERENCES = DEAD WEIGHT)

| Table | In init.sql | In migrations | Code refs |
|-------|------------|--------------|-----------|
| `webhook_deliveries` | Yes | Yes (005) | **0** |
| `contract_notes` | Yes | No | **0** |
| `recurring_invoice_items` | Yes | Yes (004/008) | **0** |
| `quote_history` | Yes | No | **0** |
| `contract_history` | Yes | No | **0** |
| `invoice_history` | Yes | No | **0** |
| `notification_settings` | Yes | No | **0** |
| `notification_log` | Yes | No | **0** |
| `document_custom_field_values` | Yes | No | **0** |

**RECOMMENDATION**: Remove these tables or build the features that use them.

---

## 3. `recurring_invoices` TABLE — DESIGN FAILURE

- The table EXISTS in schema and migrations
- But `generate_recurring_invoices.php` creates rows in the **`invoices`** table with `invoice_type='long_term'`, NOT in `recurring_invoices`
- Only 3 code references:
  1. `project_add_document.php` mapping (theoretical)
  2. `tests/comprehensive_test.php`
  3. `send_invoice_reminders.php` migration reference
- **This is a fundamentally broken design** — two invoice tables, one not used, one overloaded

**RECOMMENDATION**: Drop `recurring_invoices` and `recurring_invoice_items`. Use `invoices` with `invoice_type='long_term'` consistently.

---

## 4. TABLE DUPLICATION: `cron/src/` vs `src/`

Two parallel code trees that must be kept in sync:
- `cron/src/cron/generate_recurring_invoices.php` vs `src/cron/generate_recurring_invoices.php`
- `cron/src/cron/link_expiration_checker.php` vs `src/cron/link_expiration_checker.php`
- `cron/src/services/StripeService.php` vs `src/services/StripeService.php`
- `cron/src/utils/crypto.php` vs `src/utils/crypto.php`
- `cron/src/config/app.php` vs `src/config/app.php`

The cron container Docker build copies from `cron/` directory. The web app uses `src/`.
**This is a maintenance nightmare** — every fix must be applied twice.

---

## 5. QUESTIONABLE DESIGN PATTERNS

### A. No migration runner
- Just numbered SQL files
- No version tracking table (except `cron_job_runs` which is for cron, not schema)
- README says "run these manually"
- **Need**: A `migrations` table + runner script

### B. `public_links` — no foreign keys
- `document_id` is not constrained to quotes/contracts/invoices
- No referential integrity

### C. `financial_records` — barely used (only in tests)
- May be a future feature or dead weight

### D. `payments` INSERT inconsistency
- `stripe_webhook.php` line 205: INSERT without `client_id` — will fail if client_id is NOT NULL

### E. `project_meta` polluted with document fields
- Columns: `notes`, `terms`, `project_code` — these belong on the document tables, not a generic meta table
- Also has `client_id` FK which is redundant (projects already have client_id)

### F. Dual config system
- `app_config` table AND `config/settings.json` file
- App loads from both

### G. `document_custom_field_values` FKs only to `quotes`
- FK constraint: `REFERENCES quotes(id)` — makes it useless for contracts/invoices
- Zero code references anyway

### H. No ORM / abstraction layer
- 257 PHP files with raw PDO queries
- No model classes
- Every controller does its own SQL

---

## 6. MIGRATION FILE CHAOS

```
database/migrations/
  000_all_DEPRECATED.sql          (883 lines, dead)
  001_auth_module.sql
  002_organizations_module.sql
  003_projects_clients_module.sql
  004_documents_module.sql        (has recurring_invoices)
  005_financial_module.sql        (has webhook_deliveries)
  006_audit_system_module.sql
  007_migrate_and_drop_old_tables.sql
  007_public_links_module.sql     — DUPLICATE 007!
  008_documents_module.sql        (clean docs)
  008_seed_data.sql
  009_auth_users_module.sql
  010_financial_module.sql        (MISSING columns vs init.sql)
  011_projects_clients_module.sql (MISSING columns vs init.sql)
  012_audit_system_module.sql
  deprecated/
    001_init.sql through 014_legacy_tables.sql
```

**Problems**:
1. Duplicate migration number 007
2. Two 008s (documents + seed)
3. Migrations are NOT cumulative — init.sql is the "real" schema
4. No migration tracking — impossible to know which have run

---

## 7. COMPLETE TABLE INVENTORY (60 tables)

### Module 001: Auth (7 tables)
- users, password_resets, login_attempts, login_2fa_attempts, user_2fa, trusted_devices, trusted_ips

### Module 002: Organizations (5 tables)
- organizations, user_organizations, api_keys, api_usage, webhooks, webhook_deliveries

### Module 003: Projects & Clients (6 tables)
- clients, projects, project_meta, project_counters, project_documents, entity_links

### Module 004: Documents (11 tables)
- quotes, quote_items, contracts, contract_items, contract_signatures, contract_notes, invoices, invoice_items, invoice_notifications, recurring_invoices, recurring_invoice_items

### Module 005: Financial (10 tables)
- payments, payment_intents, auto_pay_log, payment_methods, tax_rates, item_library, receipt_stores, receipts, form_categories, form_documents, discounts, financial_records

### Module 006: Audit & System (5 tables)
- system_audit, audit_schedules, audit_schedule_logs, notification_settings, notification_log, notifications, cron_job_runs, app_config

### Module 007: Links & Customization (6 tables)
- public_links, link_resolver_config, document_custom_fields, document_settings, document_custom_field_values, archived_entities, archived_clients

---

## 8. KEY CONTROLLERS & CRON JOBS TO VERIFY

### Cron jobs (run automatically):
1. `generate_recurring_invoices.php` — Creates invoices from long_term contracts, sends reminders
2. `send_invoice_reminders.php` — Standalone due-7 and overdue-weekly reminders
3. `auto_charge_recurring.php` — Stripe auto-pay for recurring invoices
4. `auto_terminate_contracts.php` — Marks expired contracts as completed/cancelled
5. `link_expiration_checker.php` — Checks public link expirations
6. `stripe_reconciliation.php` — Reconciles Stripe payments with PA invoices

### Critical controllers:
- `src/controllers/client/clients_delete.php` — Soft delete + archive
- `src/controllers/client/clients_restore.php` — Restore from archive
- `src/controllers/contract/*.php` — 13 contract controllers
- `src/controllers/quote/*.php` — 6 quote controllers
- `src/controllers/invoice/*.php` — 6 invoice controllers
- `src/controllers/stripe/*.php` — Stripe webhook handlers
- `src/controllers/webhook/*.php` — Additional Stripe webhooks

---

## 9. RECOMMENDED ACTIONS (PRIORITIZED)

### P0 — CRITICAL (Fix or app breaks)
1. **Sync all migrations to match init.sql exactly** — every column must match
2. **Fix `project_documents.document_type` enum** to include `recurring_invoice` OR remove that mapping from code
3. **Fix `projects.status` enum** — decide canonical set, update all
4. **Fix `payments` INSERTs** — ensure all INSERTs have required columns
5. **Deduplicate `cron/src/` and `src/`** — cron should reference `src/`, not maintain copies

### P1 — HIGH (Data integrity / maintainability)
6. **Remove or implement orphan tables** — webhook_deliveries, contract_notes, *_history, notification_*, document_custom_field_values
7. **Drop `recurring_invoices` table** — migrate any data to `invoices` with `invoice_type='long_term'`
8. **Add a proper migration system** — `migrations` tracking table + runner script
9. **Add foreign keys where missing** — public_links.document_id, etc.

### P2 — MEDIUM (Design improvements)
10. **Clean up `project_meta`** — remove non-meta columns (notes, terms, project_code)
11. **Unify config system** — pick table OR file, not both
12. **Add model/entity abstraction** — at minimum a base Model class with query builder
13. **Standardize naming** — `auto_pay_enabled` on client vs contract inconsistency

### P3 — LOW (Nice to have)
14. Add database seeders for testing
15. Add schema validation tests
16. Document table relationships

---

## 10. FILES THAT NEED UPDATES FOR ANY SCHEMA CHANGE

When changing ANY table, search for references in:
- `src/controllers/*/*.php` — CRUD operations
- `src/views/pages/*/*.php` — display columns, forms
- `src/cron/*.php` — background jobs
- `cron/src/cron/*.php` — cron container copies
- `public/js/*-logic.js` — frontend form handling
- `database/init.sql` — canonical schema
- `database/migrations/*.sql` — migration files
- `tests/comprehensive_test.php` — integration tests

---

END OF BRIEFING

# PROJECT ALPHA - OPUS4.8 DEEP ARCHITECTURAL REVIEW BRIEFING

## Repository
- Location: /home/bkoltz/Project-Alpha
- Branch: dev (latest pulled)
- Language: PHP 8.3, Docker, MySQL 8.0
- Type: SaaS for quotes/contracts/invoices with Stripe integration

## Current State
- 2 commits already made by main agent:
  - ff5765c: fixed broken cron, JS calc, phantom table refs
  - 595a831: fixed document_custom_fields test schema mismatch
- 3 background kimi-k2.6:cloud agents still running:
  1. Schema Fixer: syncing migrations 001-012 to init.sql
  2. Code Repair: fixing PHP/JS phantom column references
  3. Cleanup: building migration runner, removing dead tables

## Database Architecture Issues Found

### Live DB Schema (already correct, from init.sql bootstrap)
- 25+ core tables fully operational
- All columns present: payments.surcharge_paid, payments.stripe_session_id, payments.auto_pay_attempt, payments.status
- All columns present: clients.auto_pay_enabled, clients.config, clients.stripe_customer_id, clients.stripe_payment_method_id
- projects.status enum: ('not_started','active','overdue','completed','cancelled')
- document_custom_fields has: field_data_type, field_key, field_label, default_value, min_value, max_value, is_builtin, is_enabled
- link_resolver_config has: credentials, default_expiration_days, is_enabled
- payment_methods has: config, is_active, provider

### Broken Migration Files (need fixing)
- 010_financial_module.sql: missing columns in payments (surcharge_paid, stripe_session_id, etc.)
- 011_projects_clients_module.sql: missing columns in clients (auto_pay_enabled, config, etc.)
- projects.status enum in 011: wrong values ('active','completed','on_hold','cancelled') vs init.sql
- project_documents.document_type enum: missing 'recurring_invoice' in migration
- Duplicate migration numbers: 007 (007_migrate_and_drop_old_tables.sql + 007_public_links_module.sql), 008 (008_documents_module.sql + 008_seed_data.sql)

### Dead Tables (all confirmed 0 rows in live DB)
- recurring_invoices (0 rows) - superseded by invoices with invoice_type='long_term'
- recurring_invoice_items (0 rows)
- contract_notes (0 rows)
- quote_history (0 rows)
- contract_history (0 rows)
- invoice_history (0 rows)
- webhook_deliveries (0 rows)
- notification_settings (0 rows)
- notification_log (0 rows)
- document_custom_field_values (0 rows)
- archived_entities (0 rows)
- archived_clients (0 rows)

### Code Bugs Already Fixed
- auto_charge_recurring.php: removed non-existent `recurring_schedule` column from SELECT
- invoices-edit-logic.js: fixed `discount` variable used before definition in recalcInv()
- project_add/remove_document.php: mapped `recurring_invoice` to `invoices` table (was dead `recurring_invoices`)
- comprehensive_test.php: fixed wrong table names

### Critical Infrastructure Issues
- cron/src/ is STALE COPY of src/ (Docker build copies from cron/src/)
- docker-compose.yml already fixed: added `- ./src:/var/www/src` volume mount to cron
- auto_charge_recurring.php missing from cron/src/ (cron container couldn't run it)
- No migration runner system exists (just numbered .sql files)

## Files You Must Read
1. /home/bkoltz/Project-Alpha/docs/database_audit_briefing.md (comprehensive audit)
2. /home/bkoltz/Project-Alpha/docs/supplemental_audit_findings.md (additional bugs)
3. /home/bkoltz/Project-Alpha/database/init.sql (canonical schema)
4. /home/bkoltz/Project-Alpha/database/migrations/010_financial_module.sql (broken)
5. /home/bkoltz/Project-Alpha/database/migrations/011_projects_clients_module.sql (broken)
6. /home/bkoltz/Project-Alpha/src/config/db.php (DB config)
7. /home/bkoltz/Project-Alpha/public/index.php (entry point - 803 lines)
8. /home/bkoltz/Project-Alpha/docker-compose.yml (already fixed by main agent)

## Your Task
Perform a DEEP architectural review:
1. Read the schema and identify all tables, columns, and relationships
2. Identify every column/type/default/index mismatch between init.sql and numbered migrations
3. Identify ALL PHP/JS files that reference tables/columns that don't exist or use wrong types
4. Check all cron scripts for broken logic, phantom columns, or stale table references
5. Check all controller INSERT statements for missing required columns
6. Verify enum values match between schema and code
7. Check for duplicate migration numbering conflicts
8. Look for any tables missing from init.sql but present in live DB (or vice versa)
9. Identify any security issues (SQL injection via dynamic table names, missing CSRF, etc.)
10. Recommend best practices for migration system going forward

## Output
Return a detailed markdown report with:
- Every issue found (file, line, problem, fix)
- Schema comparison: init.sql vs each migration (what's missing, extra, or wrong)
- Code references to dead/broken schema elements
- Recommended migration system architecture
- Priority-ranked fix list (critical → nice-to-have)

DO NOT make changes to files. Only report findings. The kimi agents will execute fixes.

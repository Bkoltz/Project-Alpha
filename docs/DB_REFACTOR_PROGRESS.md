# Database Refactoring Progress

**Started:** 2026-05-04 21:43 CDT
**Branch:** `db-refactor-2026-05-04`
**Status:** Committed to branch — Phase 1 Complete

## Phases

- [x] Phase 1: Create migration scripts (SQL files 002-006)
- [x] Phase 2: Multi-user/tenant model (user_organizations, organization_id additions)
- [x] Phase 3: Contract unification (3 tables → 1)
- [x] Phase 4: Invoice unification (drop on_demand_invoices)
- [x] Phase 5: Client soft deletes + archive consolidation
- [x] Phase 6: Audit log consolidation
- [x] Phase 7: PHP controller updates (accounts, auth, receipts)
- [x] Phase 8: PHP controller updates (contracts, invoices, projects)
- [x] Phase 9: PHP view updates (archived clients, contract lists, invoice lists, contract details)
- [ ] Phase 10: Remaining views (quote views, client views, project views, financial views)
- [ ] Phase 11: Cron job updates
- [ ] Phase 12: Remaining controller cleanup (quote controllers, settings controllers)
- [ ] Phase 13: Testing & cleanup
- [ ] Phase 14: Push to remote

## Commit Summary

**Commit:** `17ddbaf` on branch `db-refactor-2026-05-04`
**Files changed:** 38 files, +2833/-372 lines

### What Was Done

#### Migration Scripts (002-006)
1. **002_multi_user_tenant.sql** - Creates `user_organizations` junction table, seeds existing users, adds `organization_id` to quotes/projects/item_library/tax_rates/payment_methods, adds `deleted_at`/`archive_payload` to clients, renames `org_id` → `organization_id`
2. **003_unify_contracts.sql** - Creates unified `contracts_new` table with `contract_type`, migrates all 3 contract types with ID offsets, creates unified `contract_items_new`, builds `_contract_id_mapping` table
3. **004_unify_invoices_signatures.sql** - Adds `contract_type`/`on_demand_invoice_number`/`generated_at`/`organization_id` to invoices, migrates parentage using mapping table, drops `on_demand_invoices`, creates unified `contract_signatures_new`
4. **005_client_soft_delete_audit.sql** - Migrates `archived_clients` data into `clients` with `deleted_at`, drops `archived_clients`/`archived_entities`, merges `activity_log` into `system_audit`, drops `activity_log`
5. **006_final_cleanup.sql** - Renames old tables to `_old` suffix, renames new tables to canonical names, fixes FKs, updates `project_documents` enum values, drops mapping table

#### Controllers Updated (21 files)
- **Auth/Accounts**: Multi-tenant login, org creation on first admin, user-org linking
- **Clients**: Soft delete with archive_payload JSON, restore from soft delete
- **Contracts**: All 3 types now use unified `contracts` table with `contract_type` filter
- **Invoices**: Unified parentage, dropped `on_demand_invoices` references
- **Projects**: Updated document type mappings (dropped `long_term_contract`/`on_demand_contract`)
- **Receipts**: `org_id` → `organization_id`

#### Views Updated (5 files)
- **archived-clients.php**: Reads from `clients WHERE deleted_at IS NOT NULL`
- **long-term-contracts-list.php**: Queries unified `contracts` table
- **long-term-contract-details.php**: Uses unified table + `contract_items`
- **on-demand-contracts-list.php**: Queries unified `contracts` table
- **on-demand-invoices-list.php**: Uses `contract_type` column instead of `on_demand_contract_id`

### What Still Needs Work

#### Remaining Views (likely need updates)
- `contract/contract-details.php` (regular contracts)
- `contract/contracts-edit.php`
- `contract/contracts-list.php`
- `invoice/invoice-details.php`
- `invoice/invoices-edit.php`
- `invoice/invoices-list.php`
- `invoice/recurring-invoices-list.php`
- `client/clients-list.php`
- `client/clients-edit.php`
- `quote/*` views
- `project/*` views
- `financial/*` views (receipts/forms)

#### Cron Jobs
- `cron/generate_recurring_invoices.php` — likely references `long_term_contracts`
- `cron/stripe_reconciliation.php` — may reference old tables

#### Settings/Other Controllers
- Various settings handlers may reference old table names in SQL

### Next Steps

1. Search remaining views for old table references and update
2. Check cron jobs for old table references
3. Test the migration scripts on a fresh database
4. Push to remote when ready

### How to Apply Migrations

Run in order on your database:
```sql
SOURCE database/migrations/002_multi_user_tenant.sql
SOURCE database/migrations/003_unify_contracts.sql
SOURCE database/migrations/004_unify_invoices_signatures.sql
SOURCE database/migrations/005_client_soft_delete_audit.sql
SOURCE database/migrations/006_final_cleanup.sql
```

**Note:** Migrations 003-005 depend on the old tables existing. If you've already run them and need to re-run, restore from backup first.

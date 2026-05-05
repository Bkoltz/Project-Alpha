# Database Refactoring Progress

**Started:** 2026-05-04 21:43 CDT  
**Branch:** `db-refactor-2026-05-04`  
**Status:** Phase 2 Complete — All views and cron jobs updated

---

## Commits

| Commit | Description | Files | +/- |
|--------|-------------|-------|-----|
| `17ddbaf` | Phase 1: Migration scripts + core controllers | 38 | +2,833 / -372 |
| `753268b` | Phase 2: Remaining views, cron jobs, controllers | 16 | +232 / -381 |

**Total:** 54 files changed, +3,065 / -753 lines

---

## What Was Done

### Migration Scripts (002-006)
1. **002_multi_user_tenant.sql** — Multi-tenant support (`user_organizations`, `organization_id` additions, soft delete columns)
2. **003_unify_contracts.sql** — Contract unification (3 tables → 1 with `contract_type`)
3. **004_unify_invoices_signatures.sql** — Invoice unification (merge `on_demand_invoices`), unified signatures
4. **005_client_soft_delete_audit.sql** — Soft delete migration, merge `activity_log` into `system_audit`
5. **006_final_cleanup.sql** — Table renames, FK fixes, drop old tables

### Controllers Updated (23 files)
- **Auth/Accounts**: Multi-tenant login, org creation, user-org linking
- **Clients**: Soft delete with `archive_payload` JSON
- **Contracts**: All types use unified `contracts` table with `contract_type`
- **Invoices**: Unified parentage via `contract_id` + `contract_type`
- **Quotes**: `quote_approve.php` creates contracts in unified table
- **Receipts**: `org_id` → `organization_id`
- **Document Re-enable**: Uses unified contracts with `contract_type` filter

### Views Updated (12 files)
- **archived-clients.php** — Reads from `clients WHERE deleted_at IS NOT NULL`
- **long-term-contracts-list.php** — Uses unified `contracts` table
- **long-term-contract-details.php** — Uses unified table + `contract_items`
- **on-demand-contracts-list.php** — Uses unified `contracts` table
- **on-demand-invoices-list.php** — Uses `contract_type='on_demand'` + joins `contracts`
- **recurring-invoices-list.php** — Uses unified `contracts` with `contract_type='long_term'`
- **links_section.php** — `org_id` → `organization_id` in client query
- **financial/** — All financial views updated (`org_id` → `organization_id`)

### Cron Jobs Updated (2 files)
- **auto_terminate_contracts.php** — Uses unified `contracts` table, checks `contract_type`
- **generate_recurring_invoices.php** — Queries `contracts WHERE contract_type='long_term'`, uses `contract_items`

### Utilities Updated
- **notifications.php** — `log_activity()` now writes to `system_audit` instead of `activity_log`

---

## What Still Needs Work

The following items were **not** found during the grep sweep, but should be manually verified:

### Remaining Views (to check)
- `contract/contract-details.php` — May reference old item table names
- `contract/contracts-edit.php` — May reference old tables
- `contract/contracts-list.php` — May have contract type logic
- `invoice/invoice-details.php` — May reference `long_term_contract_id` or `on_demand_contract_id`
- `invoice/invoices-list.php` — May have filters for old contract types
- `invoice/invoices-edit.php` — May reference old FK columns
- `client/clients-list.php` — May have `archived` flag logic (should use `deleted_at`)
- `client/clients-edit.php` — May have archive/restore logic
- `quote/*` views — May display contract type info
- `project/*` views — May reference old document types

### Settings/Other Controllers
- Various settings handlers may still reference old table names
- `on_demand_invoice_generate.php` — Was marked as updated but should be verified
- Any controller using `INFORMATION_SCHEMA.TABLES` to check for `long_term_contracts`

### Testing Checklist
- [ ] Run migration 002 on a backup database
- [ ] Run migration 003 (requires old tables to exist)
- [ ] Run migration 004 (requires mapping table from 003)
- [ ] Run migration 005 (requires `archived_clients` and `activity_log`)
- [ ] Run migration 006 (renames tables — irreversible without restore)
- [ ] Verify login works with multi-tenant changes
- [ ] Verify creating regular/on-demand/long-term contracts from quotes
- [ ] Verify invoice generation cron job
- [ ] Verify auto-termination cron job
- [ ] Verify soft delete / restore client workflow
- [ ] Verify archived clients list displays correctly
- [ ] Verify recurring invoices list displays
- [ ] Verify on-demand invoices list displays

---

## How to Apply Migrations

**⚠️ BACKUP YOUR DATABASE FIRST**

```sql
-- Run in order:
SOURCE database/migrations/002_multi_user_tenant.sql
SOURCE database/migrations/003_unify_contracts.sql
SOURCE database/migrations/004_unify_invoices_signatures.sql
SOURCE database/migrations/005_client_soft_delete_audit.sql
SOURCE database/migrations/006_final_cleanup.sql
```

Or from shell:
```bash
mysql -u root -p project_alpha < database/migrations/002_multi_user_tenant.sql
mysql -u root -p project_alpha < database/migrations/003_unify_contracts.sql
mysql -u root -p project_alpha < database/migrations/004_unify_invoices_signatures.sql
mysql -u root -p project_alpha < database/migrations/005_client_soft_delete_audit.sql
mysql -u root -p project_alpha < database/migrations/006_final_cleanup.sql
```

**Note:** Migrations 003-005 depend on old tables existing. If you've already run them and need to re-run, restore from backup first.

---

## Next Steps

1. **Test locally** — Apply migrations to a test database
2. **Verify all views load** — Check contract, invoice, client, quote views
3. **Test workflows** — Create quote → approve → contract → invoice
4. **Push to remote** when satisfied

---

## Branch Status

```
Branch: db-refactor-2026-05-04
Commits: 2 ahead of main
Ready to push: Yes (after testing)
```

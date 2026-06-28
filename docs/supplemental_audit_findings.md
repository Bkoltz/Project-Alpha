# PROJECT ALPHA — SUPPLEMENTAL AUDIT FINDINGS

> **Historical audit record.** These findings were captured on June 16 before the current multi-stage Docker and unified schema work. Do not assume a finding remains open; reproduce it against the current branch and create a GitHub Issue when still applicable.
## Discovered after subagent dispatch (2026-06-16)

---

## CRITICAL BUG: `auto_charge_recurring.php` missing from cron container

**Finding**: `auto_charge_recurring.php` exists in `src/cron/` but is **ABSENT** from `cron/src/cron/`.

**Impact**: The cron container (built from `cron/` context) does NOT have this file. The crontab schedules it at line 12 but it will fail with "file not found" if the cron image is rebuilt from current state.

**Root cause**: `cron/Dockerfile` copies from `cron/src/`, not from the host `src/`. The cron/src/ tree is a stale snapshot.

**Fix**: Either:
1. Add `- ./src:/var/www/src` volume mount to cron service in docker-compose.yml, then delete `cron/src/` entirely, OR
2. Copy `auto_charge_recurring.php` into `cron/src/cron/` (but this perpetuates the sync problem)

**Recommended**: Option 1 — volume mount src/ into cron container, delete cron/src/ entirely. The only files that should remain cron-specific are `cron/Dockerfile`, `cron/crontab`, `cron/entrypoint.sh`, plus `cron_logger.php` and `process_audit_schedules.php` which are true cron-only utilities.

---

## CRITICAL BUG: `recurring_schedule` column does NOT exist

**Finding**: `src/cron/auto_charge_recurring.php` line 35 SELECTs `i.recurring_schedule` from `invoices` table.

**The `invoices` table has NO such column.** Verified by extracting full `CREATE TABLE invoices` from init.sql — confirmed: `recurring_schedule` is NOT present.

**Impact**: This cron job will crash with SQL error when it tries to SELECT a non-existent column.

**Fix**: Remove `i.recurring_schedule` from the SELECT in `auto_charge_recurring.php`. The column is never used in the rest of the file (only selected, never referenced).

---

## CRITICAL: cron/src/ and src/ are DIVERGENT (not just duplicated)

`diff -rq` reveals actual content differences in shared files:
- `cron/src/config/app.php` DIFFERS from `src/config/app.php`
- `cron/src/cron/auto_terminate_contracts.php` DIFFERS from `src/cron/auto_terminate_contracts.php`
- `cron/src/cron/link_expiration_checker.php` DIFFERS from `src/cron/link_expiration_checker.php`
- `cron/src/services/StripeService.php` DIFFERS from `src/services/StripeService.php`
- `cron/src/utils/crypto.php` DIFFERS from `src/utils/crypto.php`

**Impact**: Cron container runs DIFFERENT code than web container. Bugs fixed in web may still exist in cron. Features added to web may not work in cron.

**Fix**: As above — volume mount + delete cron/src/ duplicates.

---

## MIGRATION NUMBERING CHAOS CONFIRMED

Duplicate migration numbers exist:
- **007**: `007_migrate_and_drop_old_tables.sql` AND `007_public_links_module.sql`
- **008**: `008_documents_module.sql` AND `008_seed_data.sql`

This means no proper sequential ordering. A migration runner would need to handle this by using filename sort, not just numeric prefix.

**Recommendation**: Rename to `007a_...` / `007b_...` or renumber everything properly.

---

## `invoices` table — column `last_auto_pay_attempt` EXISTS

Good news: The `invoices` table DOES have `last_auto_pay_attempt TIMESTAMP NULL` (line 38 of invoices CREATE TABLE). So `auto_charge_recurring.php` line 172 (`UPDATE invoices SET last_auto_pay_attempt = NOW()`) is valid.

But `auto_charge_recurring.php` line 50 checks: `(i.last_auto_pay_attempt IS NULL OR i.last_auto_pay_attempt < DATE_SUB(NOW(), INTERVAL 1 DAY))` — this is valid since the column exists.

---

## `project_add_document.php` / `project_remove_document.php` — mapping issue

Both files map `recurring_invoice` => `recurring_invoices` table. Since `recurring_invoices` is unused (all recurring invoices go into `invoices` with `invoice_type='long_term'`), this mapping is dead code.

However, the `project_documents.document_type` enum in init.sql INCLUDES `'recurring_invoice'`, so the INSERT at line 19 will succeed. But the UPDATE at line 25 will target `recurring_invoices` table which is empty/unused.

**Fix**: Change mapping from `recurring_invoice` => `recurring_invoices` to `recurring_invoice` => `invoices`. Or better: remove `recurring_invoice` from the document_type enum entirely and map `long_term_contract` => `invoices` directly (which it already does).

Actually, looking more closely:
- `stored_document_type` at line 12 converts `long_term_contract` and `on_demand_contract` to `contract`
- But the `map` at line 22 maps `long_term_contract` => `contracts` and `on_demand_contract` => `contracts`
- Wait — invoices should map to `invoices` table, not `contracts`

Actually re-reading:
```php
$map = ['quote'=>'quotes', 'contract'=>'contracts', 'invoice'=>'invoices', 'recurring_invoice'=>'recurring_invoices', 'long_term_contract'=>'contracts', 'on_demand_contract'=>'contracts'];
```

This mapping is wrong for the document update. A `long_term_contract` is a CONTRACT in the `contracts` table, but its associated INVOICE lives in the `invoices` table. The `project_documents` table maps projects to documents, so `long_term_contract` should update the `contracts` table's `project_id`, which it does. That part is actually correct.

The issue is just `recurring_invoice` => `recurring_invoices` which is a dead table.

---

## ADDITIONAL ORPHAN TABLES

From the full table list (60 tables), the following have zero code references:
- `contract_notes` (line 25 in init)
- `quote_history` (line 31)
- `contract_history` (line 32)
- `invoice_history` (line 33)
- `recurring_invoices` (line 29)
- `recurring_invoice_items` (line 30)
- `webhook_deliveries` (line 13)
- `notification_settings` (line 49)
- `notification_log` (line 50)
- `document_custom_field_values` (line 58)
- `financial_records` — only referenced in tests, may be future feature

---

## DOCKER-COMPOSE VOLUME MOUNT ISSUE

Current cron service volumes:
```yaml
volumes:
  - ./src/uploads:/var/www/src/uploads
  - ./config:/var/www/config
  - config:/var/www/config/logs/cron
```

Missing: `- ./src:/var/www/src` — this means cron runs stale built-in code, not live source.

Web service HAS this mount:
```yaml
volumes:
  - ./src:/var/www/src
```

This explains why web and cron can diverge.

---

## RECOMMENDATION: `document_custom_fields` VIEW DEPENDENCIES

The `invoices-edit.php` view queries:
```sql
SELECT * FROM document_custom_fields WHERE document_type = ? AND is_enabled = 1 AND is_builtin = 0
```

This requires columns: `document_type`, `is_enabled`, `is_builtin`, `field_key`, `field_label`, `is_required`, `field_type`, `field_options`.

All these exist in init.sql. But migration 011 is MISSING `is_enabled` and `is_builtin`. The Code Repair agent must ensure the view still works.

---

END OF SUPPLEMENTAL FINDINGS

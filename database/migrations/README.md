# Project Alpha - Database Migration Files

## Overview
Individual migration files for the Project Alpha database schema.

## Files (Run in Order)

1. **001_init.sql** - Creates database `project_alpha` with utf8mb4 collation
2. **002_multi_user_tenant.sql** - Multi-user and tenant support
3. **003_unify_contracts.sql** - Contract unification
4. **004_unify_invoices_signatures.sql** - Invoice and signature unification
5. **005_client_soft_delete_audit.sql** - Client soft delete and audit
6. **006_final_cleanup.sql** - Final cleanup operations
7. **007_migrate_and_drop_old_tables.sql** - Migration and old table cleanup
8. **008_documents_module.sql** - Quotes, contracts, invoices, signatures
9. **009_auth_users_module.sql** - Users, auth, organizations, API keys
10. **010_financial_module.sql** - Payments, receipts, tax rates
11. **011_projects_clients_module.sql** - Clients, projects, settings
12. **012_audit_system_module.sql** - Audit logs, notifications, cron
13. **013_payments_stripe_update.sql** - Stripe payment columns update
14. **014_legacy_tables.sql** - Legacy tables for backward compatibility

## Deprecated Files

- **000_all_DEPRECATED.sql** - Old consolidated schema file, no longer maintained
  - Kept for historical reference only
  - Use individual migration files above instead

## Running Migrations

### Fresh Database
All `.sql` files in this directory are automatically applied in alphabetical order when the Docker container starts with a fresh database.

### Existing Database
Run individual migration files as needed:

```bash
mysql -u root -p project_alpha < 013_payments_stripe_update.sql
```

## Notes
- All migrations are idempotent (can be safely re-run)
- Files use `CREATE TABLE IF NOT EXISTS` to prevent errors on re-runs
- Foreign keys reference tables that may not exist yet - ensure proper ordering

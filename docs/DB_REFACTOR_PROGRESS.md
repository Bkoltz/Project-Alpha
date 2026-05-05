# Database Refactoring Progress

**Started:** 2026-05-04 21:43 CDT
**Branch:** `db-refactor-2026-05-04`
**Status:** In Progress

## Phases

- [x] Phase 1: Create migration scripts (SQL files 002-006)
- [x] Phase 2: Multi-user/tenant model (user_organizations, organization_id additions)
- [x] Phase 3: Contract unification (3 tables → 1)
- [x] Phase 4: Invoice unification (drop on_demand_invoices)
- [x] Phase 5: Client soft deletes + archive consolidation
- [x] Phase 6: Audit log consolidation
- [x] Phase 7: PHP controller updates (accounts, auth, receipts)
- [x] Phase 8: PHP controller updates (contracts, invoices, projects)
- [ ] Phase 9: PHP view updates (in progress)
- [ ] Phase 10: Cron job updates
- [ ] Phase 11: Remaining controller cleanup
- [ ] Phase 12: Testing & cleanup
- [ ] Phase 13: Push to branch

## Notes

### Completed Controller Updates
1. **auth_handler.php** - Added organization fetching on login, org creation on first admin register
2. **accounts_create.php** - Links new users to admin's default org via user_organizations
3. **accounts_delete.php** - user_organizations cascades automatically
4. **clients_delete.php** - Rewritten for soft delete with archive_payload JSON
5. **clients_restore.php** - Rewritten to restore soft-deleted clients
6. **receipts_handler.php** - org_id → organization_id throughout
7. **contracts_create.php** - Added contract_type='regular' to INSERT
8. **long_term_contracts_create.php** - Rewritten for unified contracts table
9. **on_demand_contracts_create.php** - Rewritten for unified contracts table
10. **long_term_contract_activate.php** - Updated for unified table
11. **long_term_contract_pause.php** - Updated for unified table
12. **long_term_contract_resume.php** - Updated for unified table
13. **long_term_contract_terminate.php** - Updated for unified table
14. **on_demand_contract_activate.php** - Updated for unified table
15. **on_demand_contract_pause.php** - Updated for unified table
16. **on_demand_contract_resume.php** - Updated for unified table
17. **on_demand_contract_terminate.php** - Updated for unified table
18. **on_demand_invoice_generate.php** - Updated for unified table, dropped on_demand_invoices
19. **invoices_mark_paid.php** - Cleaned up (was garbled)
20. **project_add_document.php** - Updated document_type map (dropped long_term_contract)
21. **project_remove_document.php** - Updated document_type map

### Migration Files Created
- `database/migrations/002_multi_user_tenant.sql` - user_organizations, soft delete columns, org_id rename
- `database/migrations/003_unify_contracts.sql` - Unified contracts table, ID mapping, contract_items
- `database/migrations/004_unify_invoices_signatures.sql` - Invoice parentage migration, unified signatures
- `database/migrations/005_client_soft_delete_audit.sql` - Soft delete migration, audit consolidation
- `database/migrations/006_final_cleanup.sql` - Table renames, final FK fixes, cleanup

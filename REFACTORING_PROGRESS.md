# Document Refactoring Progress Summary

## Completed

### Phase 1: UI Refactoring ✅ COMPLETE
- ✅ Updated `quotes-create.php` with radio buttons (Regular | Long Term | On-Demand)
- ✅ Updated `contracts-create.php` with radio buttons (Regular | Long Term | On-Demand)
- ✅ Modified all JavaScript functions to use `doc_type` instead of checkbox
- ✅ Updated field visibility logic for all three document types
- ✅ Form submission routing based on document type

### Phase 2: Document Logic Updates ✅ COMPLETE
- ✅ Updated `quotes_create.php` controller to handle `doc_type` parameter
- ✅ Updated `long_term_contracts_create.php` to save `invoice_count` for fixed_total pricing
- ✅ Verified `on_demand_contracts_create.php` logic - already properly separated
- ✅ Updated `generate_recurring_invoices.php` cron:
  - Fixed Total: Divides (total - deposit) by invoice_count
  - Tracks invoices_generated count
  - Shows proportional items with "Payment X of Y" labels
  - Marks contract complete when all invoices generated
  - Recurring Amount: Works for both ongoing and fixed end date
  - Properly links invoices to long_term_contract_id

### Phase 7: Notifications Consolidation ✅ COMPLETE
- ✅ Created `/src/views/pages/settings/notifications.php`
- ✅ Moved cron settings from documents tab
- ✅ Added email notification settings (invoices, contracts, payments, links)
- ✅ Updated `settings.php` to include notifications tab
- ✅ Updated `documents.php` with redirect notice to notifications tab

### Database Migration ✅ COMPLETE
- ✅ All schema changes consolidated into `000_all.sql`:
  - Document custom fields tables (document_custom_fields)
  - Auto-pay integration tables (payment_methods)
  - Link resolver enhancements (expiration columns in link table, link_resolver_config)
  - Multi-signature support (contract_signatures)
  - Notification settings and logging (notification_settings, notification_log)
  - Added custom_fields JSON columns to all document tables
  - Added auto-pay columns to long_term_contracts and on_demand_contracts
  - Added tracking columns (invoice_count, invoices_generated, invoice_type)
  - Added payment tracking columns to payments table

## Remaining Work

### Phase 2: Document Logic (Continued)
**Priority: HIGH**
- [ ] Update `long_term_contracts_create.php`:
  - Handle `doc_type` parameter
  - Save `invoice_count` for fixed_total pricing
  - Improve deposit calculation for fixed_total
- [ ] Update `generate_recurring_invoices.php`:
  - Implement fixed_total invoice generation (divide total by invoice_count)
  - Track invoices_generated count
  - Handle deposit invoices separately
- [ ] Test all document creation flows end-to-end

### Phase 3: Document Customization
**Priority: MEDIUM**
**Files to Create:**
- [ ] `/src/views/pages/settings/customization.php` - UI for managing custom fields
- [ ] `/src/controllers/settings/custom_fields_handler.php` - CRUD operations
- [ ] Update document creation forms to render custom fields dynamically
- [ ] Update document controllers to save/load custom field values

### Phase 4: Auto-Pay Integration
**Priority: MEDIUM**
**Files to Create:**
- [ ] `/src/services/payment/StripeAutoPayService.php` - Stripe subscription management
- [ ] `/src/webhooks/stripe_webhook_handler.php` - Handle Stripe events
- [ ] `/src/views/pages/settings/payment_methods.php` - Configure payment providers
**Files to Update:**
- [ ] Update contract creation forms to include auto-pay checkbox
- [ ] Update `generate_recurring_invoices.php` to coordinate with Stripe
- [ ] Display auto-pay status on contract details pages

### Phase 5: Link Resolver Enhancement
**Priority: LOW**
**Files to Create:**
- [ ] `/src/views/pages/settings/links.php` - Link resolver configuration UI
- [ ] `/src/services/LinkResolverService.php` - Orchestrate auto-generation
- [ ] `/src/cron/link_expiration_checker.php` - Mark expired links
**Files to Update:**
- [ ] Implement folder search in `dropbox_link_resolver.php`
- [ ] Implement folder search in `google_drive_link_resolver.php`
- [ ] Implement folder search in `s3_link_resolver.php`
- [ ] Add links display to client/organization details pages

### Phase 6: Multi-Signature Support
**Priority: LOW**
**Files to Update:**
- [ ] Update contract creation forms with signatures UI (add up to 5)
- [ ] Create signature handler controller
- [ ] Update PDF generators to render multiple signatures
- [ ] Update contract signing flow to collect all signatures
- [ ] Track signature completion status

## Testing Checklist

### Immediate Testing Needed
- [ ] Test quote creation with all 3 document types (Regular, Long Term, On-Demand)
- [ ] Test contract creation with all 3 document types
- [ ] Verify field visibility for each type
- [ ] Test calculations for each pricing mode
- [ ] Verify form submission routes to correct controller

### Future Testing
- [ ] Test custom field creation and usage
- [ ] Test auto-pay enrollment and webhooks
- [ ] Test link auto-generation
- [ ] Test multi-signature contract creation
- [ ] Test all notification types
- [ ] Test cron jobs (invoices, links, contracts)

## Notes

### Database Migration
All changes have been consolidated into `000_all.sql`. Simply reinitialize the database when ready:
```bash
# Drop and recreate database
# Then run: mysql -u root -p project_alpha < /var/www/database/migrations/000_all.sql
```

### On-Demand Logic
The On-Demand contract logic is already separated and working. It:
- Uses `price_per_invoice` as base amount
- Allows deposits
- Doesn't use automatic billing intervals
- Requires manual invoice generation

### Fixed Total Logic (Needs Implementation)
For Long-Term contracts with fixed_total pricing:
1. User enters items (total calculated from items)
2. User specifies number of invoices to divide across
3. System calculates: (total - deposit) / invoice_count = price per invoice
4. Cron generates invoices automatically based on billing interval
5. Track invoices_generated to know when contract is complete

## Estimated Completion Time
- Phase 2 (remaining): 2-3 hours
- Phase 3: 4-6 hours
- Phase 4: 4-6 hours
- Phase 5: 3-4 hours
- Phase 6: 2-3 hours
- **Total remaining: 15-22 hours**

## Quick Wins (Do First)
1. ✅ Phase 1 - UI Refactoring (DONE)
2. ✅ Phase 7 - Notifications (DONE)
3. ⏳ Complete Phase 2 - Document Logic
4. Phase 6 - Multi-Signature (independent, high value)
5. Phase 3 - Document Customization (high value)
6. Phase 4 - Auto-Pay (complex but important)
7. Phase 5 - Link Resolver (least critical)

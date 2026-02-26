# Project-Alpha TODO Implementation Order

**Last Updated**: January 3, 2026

This document provides a quick reference for the implementation order of all TODO.md items, organized by priority and dependencies.

## Quick Status

- ✅ **Phase 1**: Database schema changes - **COMPLETED**
- ⏳ **Phase 2**: Settings reorganization - **COMPLETED**
- ⏳ **Phase 3**: Document features - **COMPLETED** (uses Phase 1 columns)
- ⏳ **Phase 4**: Financial features - Ready to start (uses Phase 1 tables)
- ⏳ **Phase 5**: Audit enhancements - Ready to start (uses Phase 1 columns)
- ⏳ **Phase 6**: Logging system - Ready to start (uses Phase 1 table)

## Recommended Implementation Sequence

### 1️⃣ Start with Phase 2 (Settings Reorganization) - 2-3 hours
**Why**: Quick wins, no complex logic, improves UX immediately
- Move settings options to better locations
- No backend changes needed
- Files: `src/views/pages/settings/*.php`

### 2️⃣ Then Phase 3 (Document Features) - 4-6 hours
**Why**: High-value features that users will notice immediately
- Implement document re-enablement (un-void)
- Fix document date display
- Add "Update Document Date" buttons
- Files: New handler + all detail pages

### 3️⃣ Then Phase 4 (Financial Features) - 8-10 hours
**Why**: New functionality that expands system capabilities
- Build receipts management
- Build forms & docs (W-9) system
- Add to navigation
- Files: New pages in `src/views/pages/financial/`, new handlers

### 4️⃣ Then Phase 5 (Audit Enhancements) - 6-8 hours
**Why**: Improves existing feature with better UX and compliance
- Better date selection
- Include tax data in reports
- Add scheduling and auto-email
- Files: `src/views/pages/audit.php`, `tools/generate_audit.py`

### 5️⃣ Finally Phase 6 (Logging System) - 10-12 hours
**Why**: Foundation for monitoring and compliance, lower immediate user impact
- Install Monolog
- Create logging infrastructure
- Implement comprehensive logging
- Set up archival
- Files: New utilities, update all handlers

## Phase Details

### Phase 2: Settings Reorganization ⏳
**Estimated Time**: 2-3 hours  
**Complexity**: Low  
**Dependencies**: None

**Tasks**:
1. Move "Documents Valid for" to Documents → Customization
2. Move "Show terms on quotes" to Documents → Quotes
3. Add "Terms for On-Demand docs" field
4. Move "Enable scope field" to Documents → Quotes → Customization
5. Move "Contract Options" to Documents → Contracts → Customization
6. Remove "Advanced" section from Contracts
7. Move "Recurring invoice generation" to Documents → Invoices
8. Move "Contract Auto-termination" to Documents → Contracts
9. Rename "Billing" to "Billing & Taxes"
10. Add default values for custom fields
11. Add min/max fields for number type custom fields

**Files to Edit**: Settings pages in `src/views/pages/settings/`

---

### Phase 3: Document Features ⏳
**Estimated Time**: 4-6 hours  
**Complexity**: Medium  
**Dependencies**: Phase 1.2 (document_date columns) ✅

**Tasks**:
1. Create `src/controllers/document_reenable_handler.php`
2. Add "Re-enable" button to voided document detail pages
3. Implement logic to restore documents and related docs
4. Update `document_date` when re-enabled
5. Update PDF generation to use `document_date` column
6. Add "Update Document Date" button to detail pages
7. Create handler to update `document_date` to current timestamp
8. Show both `created_at` and `document_date` in UI

**Files to Create/Edit**:
- New: `src/controllers/document_reenable_handler.php`
- Edit: All `*-details.php` pages
- Edit: PDF generation templates

---

### Phase 4: Financial Features ⏳
**Estimated Time**: 8-10 hours  
**Complexity**: Medium-High  
**Dependencies**: Phase 1.5 (receipts table) ✅, Phase 1.6 (forms tables) ✅

#### 4.1: Receipts Management
**Tasks**:
1. Create receipts list page
2. Create receipt upload form (image, date, amount)
3. Create receipt detail/view page
4. Implement file storage in `/uploads/receipts/`
5. Add receipts link to header navigation

**Files to Create**:
- `src/views/pages/financial/receipts-list.php`
- `src/views/pages/financial/receipt-upload.php`
- `src/views/pages/financial/receipt-detail.php`
- `src/controllers/receipts_handler.php`

#### 4.2: Forms & Docs (W-9) System
**Tasks**:
1. Create forms & docs list page (card layout)
2. Create form category creation page
3. Create file upload functionality per category
4. Create detail page with large preview
5. Add download, view in new tab, replace file features
6. Add email to clients/organizations functionality
7. Implement file storage in `/uploads/forms/`
8. Add forms link to header navigation

**Files to Create**:
- `src/views/pages/financial/forms-list.php`
- `src/views/pages/financial/form-category-create.php`
- `src/views/pages/financial/form-detail.php`
- `src/controllers/forms_handler.php`

---

### Phase 5: Audit System Enhancements ⏳
**Estimated Time**: 6-8 hours  
**Complexity**: Medium  
**Dependencies**: Phase 1.3 (tax_amount, tax_county columns) ✅

**Tasks**:
1. Auto-select current year date range (Jan 1 - Dec 31)
2. Convert year selector to date picker
3. Add quick presets: "Last Quarter", "Last Month", "All Time", "Current Year"
4. Default to exclude unpaid invoices (only paid/partial)
5. Update Python script to include tax columns:
   - Date, Client, Doc number, invoice tax, tax county, amount paid, payment method, discount, running total
6. Add toggle options: include invoices, contracts, quotes
7. Add option to generate PDF (default no)
8. Add UI for scheduling audit generation
9. Add input for up to 5 email addresses
10. Implement automated email delivery
11. Ensure audit records are read-only in system

**Files to Edit**:
- `src/views/pages/audit.php`
- `tools/generate_audit.py`

**Files to Create**:
- Scheduler script for automated generation

---

### Phase 6: Logging System ⏳
**Estimated Time**: 10-12 hours  
**Complexity**: High  
**Dependencies**: Phase 1.4 (system_audit table) ✅

#### 6.1: Infrastructure Setup
**Tasks**:
1. Install Monolog via Composer
2. Create `src/utils/logger.php` helper utility
3. Configure RotatingFileHandler (10MB rotation, 30 files)
4. Set up JSON-lines format
5. Create `/logs/` directory
6. Create `src/config/logging.php`

#### 6.2: User Action Logging
**Tasks**:
1. Log document creation with metadata
2. Log status transitions with old/new state
3. Log financial field edits with before/after values
4. Log file uploads/downloads

**Files to Edit**: All document handlers

#### 6.3: System Event Logging
**Tasks**:
1. Log scheduled job start/finish/errors
2. Log sync task results
3. Log API interactions with correlation IDs
4. Log unhandled exceptions

**Files to Edit**: Cron scripts, API handlers, error handler

#### 6.4: Security Logging
**Tasks**:
1. Log failed logins
2. Log successful logins
3. Log password resets
4. Log permission denials
5. Log role changes

**Files to Edit**: Authentication handlers, permission middleware

#### 6.5: Log Archival
**Tasks**:
1. Create weekly cron job to export old logs
2. Create archival script
3. Configure S3/TrueNAS backup destination

**Files to Create**: Archival script, cron configuration

---

## Notes on Phase 1.1 (Convert to UNSIGNED)

The TODO.md mentioned converting all INT/BIGINT to UNSIGNED. This was **not included** in Phase 1 because:

1. It's a **massive schema change** affecting all tables
2. Requires updating ALL foreign key relationships
3. Risk of breaking existing code that assumes signed integers
4. Should be done during a major version upgrade with full testing
5. Not required for any of the new features

**Recommendation**: Plan this as a separate maintenance task during a scheduled downtime window, not part of feature development.

---

## Starting Development

To begin implementation:

1. Review the full plan: `PROJECT-ALPHA TODO IMPLEMENTATION PLAN`
2. Start with Phase 2 (Settings) for quick wins
3. Move to Phase 3 (Documents) for high-value features
4. Complete Phases 4-6 as time permits

All database dependencies are satisfied. You can work on any phase now.

## Questions or Changes?

If priorities change or new requirements emerge, update both:
- This document (IMPLEMENTATION_ORDER.md)
- The full plan document
- TODO.md as items are completed

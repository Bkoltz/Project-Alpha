# Project Alpha — TODO

Last updated: 2026-06-20

## Completed Items (do not redo)

- [x] Tax rates table + CRUD UI under Settings → Billing
- [x] Audit report system with CSV/PDF generation and email scheduling
- [x] Receipt management system for business expense tracking
- [x] Forms & docs storage (W-9, W-8, etc.) with client/org email
- [x] Logging system — Monolog JSON rotating logs, system_audit DB table, audit middleware
- [x] Custom field system — document_custom_fields table, seeded with deposit + fulfillment_date
- [x] Download buttons on detail pages (next to View PDF)
- [x] Document re-enablement (un-void) with related document restoration
- [x] Document date display (creation date, not current date) + manual date update button
- [x] On-demand contract/quote type with flexible billing
- [x] Twig templating for list view filters (document-filter.html.twig)
- [x] Unified schema — quote_type, contract_type, invoice_type columns (replaced is_long_term/is_on_demand + separate tables)
- [x] Expense tracking — CSV import, vendor/category/mileage CRUD, financial dashboard
- [x] Security hardening — CSRF, rate limiting, 2FA, password policy, audit logging, encrypted backups, ClamAV scanning
- [x] Legal pages — ToS, Privacy Policy, AUP, DMCA, Data Retention + ToS acceptance gate
- [x] GDPR/CCPA — Data export and account deletion
- [x] Backup settings page + encrypted backup scripts
- [x] Docker Compose simplification — 3 passwords, no .env, TRUSTED_PROXIES
- [x] JS consolidation — all files in public/assets/js/
- [x] Redesigned main dashboard with SVG charts, stat cards, health checks
- [x] Mobile responsive topbar + slide-in nav drawer
- [x] Footer with legal links (ToS, Privacy, DMCA, etc.)

## Remaining / Future Items

### SQL
- [ ] Consider making all INT/BIGINT columns UNSIGNED (no negative numbers needed for doc increments)

### Reporting
- [ ] Advanced reporting — P&L, balance sheet, custom chart builder (currently only basic CSV + financial dashboard)
- [ ] Mileage IRS form auto-fill (mileage logs tracked but no IRS form generation)

### Integrations
- [ ] Vendor bill-pay integration (vendors tracked for expenses only, no ACH/check)
- [ ] Public client portal (clients can view public links and pay, but no persistent login)

### Mobile
- [ ] PWA packaging (UI is responsive but not packaged as PWA or native app)

### Multi-tenant
- [ ] Multi-organization support (currently hardcoded to org_id=1)
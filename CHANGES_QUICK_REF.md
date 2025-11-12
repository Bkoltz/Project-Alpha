# Quick Reference: Files Changed

## Summary of All Modifications

### Configuration & Defaults
| File | Change | Lines | Purpose |
|------|--------|-------|---------|
| `src/config/app.php` | Added defaults | ~2 lines | Added `invoice_auto_send_due_7days` and `invoice_auto_send_overdue_weekly` config keys |
| `database/migrations/000_all.sql` | New table + altered table | ~12 + 2 lines | Added `invoice_notifications` table; added `is_extra_charge` column to `invoice_items` |

### Cron Jobs
| File | Type | Size | Purpose |
|------|------|------|---------|
| `src/cron/generate_recurring_invoices.php` | Modified | +~130 lines | Enhanced to send due-7 and overdue-weekly invoice reminders |
| `src/cron/send_invoice_reminders.php` | **NEW** | ~260 lines | Standalone cron for invoice reminders (can run independently) |

### Settings & Handlers
| File | Change | Purpose |
|------|--------|---------|
| `src/controllers/settings_handler.php` | Added 2 checkbox inputs | Persist `invoice_auto_send_due_7days` and `invoice_auto_send_overdue_weekly` |
| `src/views/pages/settings.php` | Added fieldset in Invoices tab | UI for enabling/disabling invoice auto-emails |

### Invoice Management - Edit
| File | Change | Purpose |
|------|--------|---------|
| `src/controllers/invoice/invoices_update.php` | **Refactored** | Only updates extra charges (is_extra_charge=1); preserves contract items |
| `src/views/pages/invoice/invoices-edit.php` | **Enhanced** | New "Extra Charges" section with add/edit/remove UI |

### Invoice Display & PDF
| File | Change | Purpose |
|------|--------|---------|
| `src/views/pages/invoice/invoice-print.php` | Modified | Fetch `is_extra_charge` flag; render extra charges with yellow badge |

### Notifications UI (Admin)
| File | Type | Purpose |
|------|------|---------|
| `src/controllers/invoice/notifications-list.php` | **NEW** | Query controller for notification list with filtering & pagination |
| `src/views/pages/invoice/notifications-list.php` | **NEW** | Admin UI page showing invoice reminders with filters |

---

## Changeset by Feature

### Feature 1: Invoice Auto-Emails
**Files**: 5 modified
- ✅ `src/config/app.php` — Config defaults
- ✅ `src/controllers/settings_handler.php` — Settings persistence
- ✅ `src/views/pages/settings.php` — Settings UI
- ✅ `src/cron/generate_recurring_invoices.php` — Embedded reminder logic
- ✅ `database/migrations/000_all.sql` — Schema for `invoice_notifications`

### Feature 2: Separate Cron
**Files**: 1 created
- ✅ `src/cron/send_invoice_reminders.php` — Standalone cron (~260 lines)

### Feature 3: Notifications Admin UI
**Files**: 2 created
- ✅ `src/controllers/invoice/notifications-list.php` — Query controller
- ✅ `src/views/pages/invoice/notifications-list.php` — Admin page

### Feature 4: Extra Charges
**Files**: 4 modified
- ✅ `database/migrations/000_all.sql` — Schema: `is_extra_charge` column
- ✅ `src/controllers/invoice/invoices_update.php` — Refactored to handle extras
- ✅ `src/views/pages/invoice/invoices-edit.php` — Extra charges UI section
- ✅ `src/views/pages/invoice/invoice-print.php` — PDF rendering with badge

---

## Access Points (User Flows)

### Settings
- **Path**: `/?page=settings`
- **Tab**: Settings → Invoices
- **New section**: "Automatic Invoice Emails" with 2 toggles

### Notifications Admin
- **Path**: `/?page=invoice/notifications-list`
- **Filters**: Type, Invoice ID, Date range
- **Display**: Table with pagination

### Invoice Edit (with Extra Charges)
- **Path**: `/?page=invoice/invoices-edit&id={id}`
- **New section**: "Extra Charges (editable)" with add/remove UI
- **Contract section**: Read-only (unchanged)

### Invoice PDF (with Extra Charge Badge)
- **Path**: `/?page=invoice/invoice-pdf&id={id}`
- **Rendering**: Extra charge rows highlighted yellow with badge

---

## Database Changes Required

### Pre-Migration Backup
```bash
mysqldump -u root -p project_alpha > project_alpha_backup_$(date +%Y%m%d_%H%M%S).sql
```

### Apply Schema
```bash
# Full re-init
mysql -u root -p project_alpha < database/migrations/000_all.sql

# OR selective ALTER
mysql -u root -p project_alpha -e "
CREATE TABLE IF NOT EXISTS invoice_notifications (...);
ALTER TABLE invoice_items ADD COLUMN is_extra_charge TINYINT(1) DEFAULT 0;
ALTER TABLE invoice_items ADD INDEX idx_invoice_items_extra (is_extra_charge);
"
```

---

## Testing by Feature

### ✅ Syntax Validation
All files validated with `php -l` — **PASS**

### 🧪 Auto-Email Testing
1. Enable toggles in Settings → Invoices
2. Run `php src/cron/send_invoice_reminders.php` manually
3. Check `invoice_notifications` table for new rows
4. Verify SMTP logs show email sent

### 🧪 Notifications UI Testing
1. Create test invoices with known due dates
2. Navigate to `/?page=invoice/notifications-list`
3. Test filters (type, invoice ID, date range)
4. Test pagination

### 🧪 Extra Charges Testing
1. Edit an invoice
2. Click "+ Add Extra Charge"
3. Fill in description, qty, price
4. Save invoice
5. View PDF — verify yellow badge and highlighting

---

## Rollback Instructions

If needed, rollback is safe:

1. **Settings**: New config keys default to 0 (off) — no action needed
2. **Cron**: Stop/remove new cron entries from crontab
3. **DB**: Drop `invoice_notifications` table or leave as-is (harmless)
4. **Extra charges**: Revert 4 invoice files to previous version; set all `is_extra_charge=0` via SQL

```sql
UPDATE invoice_items SET is_extra_charge=0;
```

---

## Performance Considerations

- **invoice_notifications** table growth: ~50-100 rows/day (depends on invoice volume)
- **Query indexes**: Added on (invoice_id) and (type) for quick lookups
- **Cron runtime**: ~5-10 seconds (depends on SMTP timeout)
- **Pagination**: 50 items/page in notifications list — adjust if needed

---

## Monitoring Metrics

Track these going forward:

1. **invoice_notifications table size** — Growing linearly is healthy
2. **Cron execution time** — Should stay <30 seconds
3. **Email bounce rate** — Monitor SMTP logs
4. **Extra charges per invoice** — Average number of extras added
5. **Notification resend requests** — Manual resends via admin UI (future feature)


# Integration Testing Guide

## Pre-Integration Checklist

Before deploying to production, complete these tests in order:

---

## Test 1: Database Schema

### Objective
Verify that all database schema changes are applied correctly.

### Steps

1. **Backup current database**
   ```bash
   mysqldump -u root -p project_alpha > project_alpha_pre_migration.sql
   ```

2. **Apply migrations**
   ```bash
   # Option A: Full re-init
   mysql -u root -p project_alpha < database/migrations/000_all.sql
   
   # Option B: Selective ALTER (if tables already exist)
   mysql -u root -p << 'EOF'
   USE project_alpha;
   
   -- Check if tables exist and create/alter as needed
   CREATE TABLE IF NOT EXISTS invoice_notifications (
     id INT AUTO_INCREMENT PRIMARY KEY,
     invoice_id INT NOT NULL,
     type VARCHAR(32) NOT NULL,
     sent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
     INDEX idx_invnot_invoice (invoice_id),
     INDEX idx_invnot_type (type),
     CONSTRAINT fk_invnot_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
   ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
   
   ALTER TABLE invoice_items 
     ADD COLUMN IF NOT EXISTS is_extra_charge TINYINT(1) NOT NULL DEFAULT 0,
     ADD INDEX IF NOT EXISTS idx_invoice_items_extra (is_extra_charge);
   EOF
   ```

3. **Verify schema**
   ```sql
   SHOW TABLES LIKE 'invoice_notifications';
   DESCRIBE invoice_items;  -- Should show is_extra_charge column
   SHOW INDEXES FROM invoice_notifications;
   SHOW INDEXES FROM invoice_items;
   ```

**Expected Result**: ✅ Both tables exist with correct columns and indexes

---

## Test 2: Settings UI & Persistence

### Objective
Verify that invoice auto-email settings can be toggled and persisted.

### Steps

1. **Navigate to Settings**
   - Open web browser to `http://localhost/?page=settings`
   - Go to "Invoices" tab

2. **Locate "Automatic Invoice Emails" section**
   - Should be at bottom of Invoices tab
   - Two checkboxes:
     - [ ] Send reminder when invoice is due in 7 days
     - [ ] Send weekly reminder for overdue invoices

3. **Test persistence**
   - Check both checkboxes
   - Click "Save" button
   - Reload page — both should still be checked
   - Uncheck both and save
   - Reload page — both should be unchecked

4. **Verify settings file**
   ```bash
   # Check raw JSON
   cat config/settings.json | grep -i "invoice_auto"
   ```

**Expected Result**: ✅ Settings toggle persists across page reloads and file contains correct values

---

## Test 3: Cron Syntax & Dry-Run

### Objective
Verify cron scripts load correctly and can run without errors.

### Steps

1. **Validate PHP syntax**
   ```bash
   php -l src/cron/send_invoice_reminders.php
   php -l src/cron/generate_recurring_invoices.php
   ```

2. **Dry-run send_invoice_reminders.php**
   ```bash
   # First, enable settings in database
   mysql project_alpha -e "UPDATE settings_json SET invoice_auto_send_due_7days=1, invoice_auto_send_overdue_weekly=1 LIMIT 1;"
   
   # Run cron in test environment
   php src/cron/send_invoice_reminders.php 2>&1 | head -50
   ```

3. **Check for errors**
   - Should see: `[INFO] send_invoice_reminders started` or similar
   - Should NOT see: Fatal errors, undefined functions, database connection errors
   - Look for: `email sent successfully`, `notification recorded`, or `no invoices due` messages

4. **Verify logs**
   ```bash
   tail -20 config/uploads/logs/cron_reminders.log  # or wherever logged
   ```

**Expected Result**: ✅ Cron runs without fatal errors; logs show expected behavior

---

## Test 4: Email Delivery (SMTP Test)

### Objective
Verify that emails are actually sent when reminders trigger.

### Steps

1. **Set up test SMTP sink** (choose one)
   - **Option A: Use Mailtrap** — Create account, get credentials, update settings.json
   - **Option B: Use local mail sink** — `mailhog` or similar
   - **Option C: Use test mail client** — Log actual emails sent to test inbox

2. **Create test invoice**
   - Go to Invoices → Create
   - Select a test client
   - Set due date to tomorrow (or adjust test data)
   - Add item with $100
   - Save

3. **Trigger reminder manually**
   ```bash
   # Run cron
   php src/cron/send_invoice_reminders.php
   
   # Or simulate directly in PHP
   php -r "
   include 'config/db.php';
   include 'config/app.php';
   \$inv = \$pdo->query('SELECT * FROM invoices ORDER BY id DESC LIMIT 1')->fetch();
   if (\$inv) echo 'Latest invoice ID: ' . \$inv['id'] . ', Due: ' . \$inv['due_date'];
   "
   ```

4. **Verify email received**
   - Check Mailtrap/MailHog inbox
   - Should see email with invoice link
   - Link should include public_links token

5. **Check database**
   ```sql
   SELECT * FROM invoice_notifications ORDER BY sent_at DESC LIMIT 5;
   SELECT * FROM public_links ORDER BY created_at DESC LIMIT 5;
   ```

**Expected Result**: ✅ Email delivered to test SMTP; records in database confirm send

---

## Test 5: Notification Admin UI

### Objective
Verify that notification list page displays and filters correctly.

### Prerequisites
- Complete Test 4 (have some reminders sent)

### Steps

1. **Navigate to notifications page**
   - URL: `http://localhost/?page=invoice/notifications-list`

2. **Verify initial display**
   - Should show table of notifications (if any reminders sent)
   - Columns: Invoice #, Client, Amount, Due Date, Status, Reminder Type, Sent At, Email

3. **Test filter: Type**
   - Select "Due in 7 Days" from Type dropdown
   - Click Filter
   - Should show only due_7 reminders
   - Select "Overdue Weekly"
   - Should show only overdue_weekly reminders

4. **Test filter: Invoice ID**
   - Enter a specific invoice ID
   - Click Filter
   - Should show only that invoice's reminders

5. **Test filter: Date Range**
   - Set From date to today
   - Set To date to today
   - Click Filter
   - Should show reminders sent today

6. **Test pagination**
   - If >50 reminders, click "Next" button
   - Should show next page
   - Click specific page number
   - Should jump to that page

7. **Clear filters**
   - Click "Clear" button
   - Should reset all filters and show all reminders again

**Expected Result**: ✅ All filters work; pagination functions; UI displays correctly

---

## Test 6: Extra Charges UI

### Objective
Verify that extra charges can be added, edited, and removed on invoices.

### Steps

1. **Create a base invoice**
   - Go to Invoices → Create
   - Fill in client, due date, add one item ($100 for "Consulting")
   - Save

2. **Edit the invoice**
   - Go to Invoices → List
   - Click the invoice to edit it
   - Scroll down to "Extra Charges (editable)" section

3. **Add first extra charge**
   - Click "+ Add Extra Charge" button
   - Enter: Description="Travel", Qty=1, Price=50
   - Click Remove button — row should disappear
   - Click "+ Add Extra Charge" again and enter same

4. **Add second extra charge**
   - Click "+ Add Extra Charge" button
   - Enter: Description="Overage Hours", Qty=10, Price=25
   - Total should now show: $1650 (100 + 50 + 250)

5. **Edit extra charge**
   - Change first extra charge price from 50 to 75
   - Verify totals update in real-time on form

6. **Save invoice**
   - Click "Update Invoice" button
   - Should redirect to invoice list with "updated" message

7. **Re-edit to verify persistence**
   - Edit same invoice again
   - Extra Charges section should still show both additions
   - Values should match what you entered

**Expected Result**: ✅ Extra charges persist; UI allows add/edit/remove; totals recalculate

---

## Test 7: Invoice PDF with Extra Charges

### Objective
Verify that PDFs render extra charges with distinct marking.

### Steps

1. **View invoice with extra charges**
   - Go to Invoices → List
   - Click an invoice that has extra charges

2. **Generate PDF**
   - Click "View PDF" button
   - PDF should open in new tab

3. **Inspect PDF visually**
   - Contract items should appear normally (white background)
   - Extra charge items should have:
     - Yellow background (#fffbeb)
     - Yellow badge label "Extra Charge"
   - All items (contract + extra) should be in line items table

4. **Verify PDF calculation**
   - Subtotal = sum of all items (contract + extra)
   - Discount applied to full subtotal
   - Tax applied post-discount
   - Total = (subtotal - discount) + tax
   - Example: $100 (contract) + $50 (extra) = $150 subtotal → apply $15 discount → $135 → 10% tax = $13.50 → $148.50 total

5. **Print to file (optional)**
   ```bash
   # Save PDF for manual inspection
   curl -o invoice_test.pdf "http://localhost/?page=invoice/invoice-pdf&id=123"
   ```

**Expected Result**: ✅ PDF renders correctly; extra charges visually distinct; math correct

---

## Test 8: Duplicate Prevention

### Objective
Verify that reminders are not sent multiple times for the same invoice.

### Steps

1. **Create test invoice due in 7 days**
   ```bash
   mysql project_alpha -e "
   INSERT INTO invoices (client_id, doc_number, status, due_date, subtotal, total)
   VALUES (1, 999, 'unpaid', DATE_ADD(NOW(), INTERVAL 7 DAY), 100, 100);
   "
   ```

2. **Get the invoice ID**
   ```sql
   SELECT id FROM invoices WHERE doc_number=999;
   -- Assume ID = 1000
   ```

3. **Run cron twice**
   ```bash
   php src/cron/send_invoice_reminders.php
   php src/cron/send_invoice_reminders.php  # Run again immediately
   ```

4. **Check notifications**
   ```sql
   SELECT COUNT(*) FROM invoice_notifications WHERE invoice_id=1000 AND type='due_7';
   ```
   Should show: **1** (not 2)

5. **Test overdue weekly cadence**
   - Create overdue invoice
   - Run cron twice (5 seconds apart)
   - Check: `SELECT * FROM invoice_notifications WHERE type='overdue_weekly'`
   - Should show: **1** entry (not 2)
   - Wait 7 days (or update test data)
   - Run cron again
   - Should show: **2** entries total (weekly reminder sent)

**Expected Result**: ✅ Due-7 reminders sent once; overdue reminders sent weekly (max once per 7 days)

---

## Test 9: Public Link Expiry

### Objective
Verify that public links in reminder emails have correct expiry.

### Steps

1. **Check public_links table after sending reminder**
   ```sql
   SELECT * FROM public_links ORDER BY created_at DESC LIMIT 1;
   ```
   Should show:
   - type='invoice'
   - expires_at = NOW() + documents_valid_days (e.g., 7 days)
   - revoked=0

2. **Verify link is accessible**
   - Get token from public_links table
   - Visit: `http://localhost/?page=public_view/invoice&token=<token>`
   - Should show invoice without requiring login

3. **Test expired link**
   - Update test link to be already expired:
     ```sql
     UPDATE public_links SET expires_at=NOW() WHERE type='invoice' AND id=<id>;
     ```
   - Visit expired token URL
   - Should show: "Link expired" or similar error

**Expected Result**: ✅ Valid links work; expired links blocked

---

## Test 10: Error Handling

### Objective
Verify graceful error handling in cron and UI.

### Steps

1. **Test invalid SMTP config**
   - Temporarily break SMTP password in settings.json
   - Run cron
   - Should log error but not crash
   - Should not insert duplicate notifications

2. **Test database connection failure**
   - Stop database temporarily
   - Run cron
   - Should exit with error message (not PHP fatal)
   - Restart database
   - Run cron again — should work

3. **Test UI with missing settings**
   - Delete settings.json
   - Load Settings page
   - Should use defaults, not crash
   - Edit extra charges and save
   - Should work (using defaults)

4. **Test notification list with no data**
   - Clear invoice_notifications table
   - Navigate to notifications list
   - Should show: "No reminders found"

**Expected Result**: ✅ All errors handled gracefully; user-friendly messages

---

## Test 11: Performance Baseline

### Objective
Establish performance baseline before production.

### Steps

1. **Cron execution time**
   ```bash
   time php src/cron/send_invoice_reminders.php
   ```
   Expected: <10 seconds (adjust based on invoice volume)

2. **Notification list performance**
   - Insert 10,000 test notifications
   - Load notifications list with filters
   - Should load in <2 seconds

3. **PDF generation time**
   - Generate 5 invoices with extra charges
   - Measure PDF generation time
   - Expected: <3 seconds per invoice

**Expected Result**: ✅ All operations complete within acceptable timeframes

---

## Regression Testing

### Objective
Verify that existing functionality still works.

### Checklist
- [ ] Create invoice (without extra charges)
- [ ] Edit invoice (contract items read-only)
- [ ] Apply discount to invoice
- [ ] Generate invoice PDF
- [ ] Email invoice to client
- [ ] Mark invoice as paid
- [ ] Create quote → create contract → create invoice workflow
- [ ] Search/filter invoices by client, status, date
- [ ] Archive old invoices

---

## Sign-Off

When all tests pass, fill out:

```
Feature: Invoice Auto-Email & Extra Charges
Tester: ________________
Date: ________________
Result: ✅ PASS / ❌ FAIL
Notes: ________________
```

---

## Troubleshooting

### Issue: "Table 'invoice_notifications' doesn't exist"
**Solution**: Run migrations (Test 1)

### Issue: Settings checkboxes don't save
**Solution**: Check `src/controllers/settings_handler.php` for POST key names

### Issue: Cron script hangs
**Solution**: Check SMTP timeout in `src/utils/smtp.php` or `mailer.php`

### Issue: PDF extra charges not highlighting
**Solution**: Verify `is_extra_charge` flag is set (1) in database

### Issue: Duplicate reminders sent
**Solution**: Check `invoice_notifications` index and query logic in cron

---

**Last Updated**: Nov 12, 2025

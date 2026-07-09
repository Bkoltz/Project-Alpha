# PA Comprehensive Plan: Bug Fixes, QA, Features, Auto-Refresh

> **Historical record.** This June 21 plan drove an earlier implementation pass. Its completed items and code excerpts are not current operating instructions. Open GitHub Issues are the current backlog.

> Generated 2026-06-21. Combines quote approval bug investigation, API research, hourly billing research, and auto-refresh request.

---

## 1. QUOTE APPROVAL BUG (FIX IMMEDIATELY)

### Root Cause
The quote approval controller works correctly — the quote status changes to "approved" and the page redirects to the quotes list with `approved=1`. However, no contract or invoice is generated because the settings `quote_auto_create_contract` and `quote_auto_create_invoice` are set to `0` in settings.json.

The controller logic:
```php
$autoCreateContract = !isset($appConfig['quote_auto_create_contract']) || !empty($appConfig['quote_auto_create_contract']);
```
Since the value IS set (to `0`), `!isset()` returns false. `!empty('0')` also returns false. So `$autoCreateContract = false`.

### Fix
Two parts:
1. **Settings fix**: Change the default values in settings_handler.php from `0` to `1` (or `'1'`) so new installations auto-create by default. The user can disable in Settings if desired.
2. **UX fix**: When a quote is approved but auto-create is disabled, show a flash message: "Quote approved. Contract and invoice were not auto-generated (disabled in Settings). You can create them manually from the quote."

### Files to change
- `src/controllers/settings_handler.php` — change default from `0` to `1`
- `src/controllers/quote/quote_approve.php` — add flash message when auto-create is disabled
- `src/views/pages/settings/system.php` or `notifications.php` — add checkboxes for "Auto-create contract on quote approval" and "Auto-create invoice on quote approval" if they don't exist

### Also: enable the settings for the current installation
```sql
INSERT INTO app_config (organization_id, config_key, config_value) VALUES (0, 'quote_auto_create_contract', '1') ON DUPLICATE KEY UPDATE config_value = '1';
INSERT INTO app_config (organization_id, config_key, config_value) VALUES (0, 'quote_auto_create_invoice', '1') ON DUPLICATE KEY UPDATE config_value = '1';
```

---

## 2. FULL USER-FLOW QA TESTING

### Approach
Dispatch a QA subagent with browser tools to click through every user flow as a real user would. Test:

**Document flows:**
- Create quote -> add line items -> save -> approve -> verify contract + invoice created
- Create quote -> deny -> verify status changed
- Create contract manually -> activate -> complete
- Create invoice manually -> mark paid -> verify payment recorded
- Long-term quote -> approve -> verify contract with billing intervals
- On-demand quote -> approve -> verify contract

**Client/project flows:**
- Create client -> edit -> delete (soft) -> restore
- Create project -> add documents -> view project

**Financial flows:**
- Create expense -> categorize -> export
- Create vendor -> add expense
- Mileage entry -> export
- Financial dashboard -> verify charts render
- Audit report -> generate -> export CSV/PDF

**Settings flows:**
- Change brand name -> verify updates everywhere
- Upload logo -> verify shows in header and PDFs
- Configure tax rates -> verify applied to new quotes
- Backup now -> verify backup created
- Restore from backup -> verify data restored

**Public link flows:**
- Create public link for quote -> open in new tab -> approve/deny as client
- Create public link for contract -> sign as client
- Create public link for invoice -> pay via Stripe (test mode)

**Edge cases:**
- Empty quote (no line items) -> approve
- Quote with 0 total -> approve
- Delete a client that has quotes/contracts/invoices
- Duplicate doc_number
- Concurrent edit (two tabs editing same quote)

---

## 3. API INTEGRATIONS (PRIORITIZED)

### Phase 1 — High value, moderate effort

| Integration | Why | Complexity |
|---|---|---|
| **Transactional Email** (Postmark/SendGrid) | Invoice notifications, password reset, reminders currently don't send without SMTP. This is the #1 blocker for real business use. | Low — PHP SDK, API key in settings |
| **E-signature** (Dropbox Sign/HelloSign) | PA has public signing links but native e-sig improves enforceability and audit trail. ESIGN/UETA compliance. | Medium — PHP SDK, webhook status sync |
| **Time tracking import** (Toggl/Harvest/Clockify) | Service businesses bill by the hour. Import tracked time as invoice line items. | Low-Medium — REST API, import UI |
| **Webhooks (outbound)** | Let external systems react to PA events (quote.approved, invoice.paid, etc.). Enables Zapier/Make integrations. | Medium — endpoint management, retry, signatures |
| **QBO/CSV export** | Accountants need to import PA data into QuickBooks. CSV exists; add QBO/IIF format. | Low-Medium |

### Phase 2 — Medium value, higher effort

| Integration | Why | Complexity |
|---|---|---|
| **QuickBooks Online sync** | Two-way sync of invoices, payments, customers. The #1 requested integration for invoice SaaS. | High — OAuth, chart-of-accounts mapping, sync conflicts |
| **PayPal payments** | Clients want PayPal option alongside Stripe. | Medium-High |
| **CRM sync** (Pipedrive/HubSpot) | Sales teams want finance context in CRM. | Medium |
| **Project management** (Asana/Monday) | Auto-create tasks from approved quotes. | Low-Medium |
| **Cloud storage** (Google Drive/Dropbox) | Attach docs from cloud storage. | Low |
| **Physical mail** (Lob/PostGrid) | Mail paper invoices via API. | Low-Medium |
| **SMS notifications** (Twilio) | Text overdue invoice reminders. | Low |

---

## 4. HOURLY BILLING

### Recommendation: Option B — Extend line items + add time_entries table

The existing line item schema already supports hourly billing: `quantity` (decimal 10,2) can hold hours, `unit_price` holds the hourly rate, `line_total` = quantity × unit_price. A line item with quantity=2.5 and unit_price=75.00 is "2.5 hours at $75/hr."

### Implementation plan

**Phase 1: Manual hourly line items (minimal change)**
- Add "Hourly Rate" as a category in the item library
- When creating a quote/invoice line item, if the item is an hourly service, the quantity field shows "Hours" instead of "Qty"
- No schema change needed — just UI labeling

**Phase 2: Time tracking (new table + UI)**
- Add `time_entries` table:
  ```sql
  CREATE TABLE time_entries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    organization_id INT,
    user_id INT NOT NULL,
    client_id INT,
    project_id INT,
    description TEXT,
    started_at DATETIME,
    ended_at DATETIME,
    hours DECIMAL(10,2) NOT NULL DEFAULT 0,
    billable TINYINT(1) DEFAULT 1,
    billed TINYINT(1) DEFAULT 0,
    rate DECIMAL(10,2) DEFAULT 0,
    invoice_item_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  );
  ```
- Add optional `hours` and `time_entry_id` columns to invoice_items
- UI: Time tracking page with clock in/out timer + manual entry
- Invoice creation: "Add from tracked time" button that pulls unbilled time entries, groups by rate/description, creates line items
- Mark time entries as `billed` when added to an invoice

**Phase 3: External time tracking import**
- Import from Toggl/Harvest/Clockify APIs
- Map external projects to PA clients/projects

### Why not Option A (new document type)
- All three competitors (QuickBooks, FreshBooks, Harvest) use the time-entry → line-item model, not a separate document type
- Time entries ultimately become invoice line items regardless of document type
- Adding a new document type duplicates the entire quote/contract/invoice lifecycle
- Option B reuses all existing logic with minimal schema change

---

## 5. AUTO-REFRESH EVERY 5 MINUTES

### Approach
Add a JavaScript auto-refresh in the header partial that reloads the current page every 5 minutes when the user is active. Use `setTimeout` with `location.reload()`.

### Implementation
In `src/views/partials/header.php`, add before the closing `</head>` or at the end of the body:
```html
<script>
setTimeout(function() { location.reload(); }, 300000);
</script>
```

### Considerations
- Only refresh if the user is on the page (not in a background tab) — use `document.visibilityState`
- Don't refresh if the user is in the middle of filling out a form — check for dirty forms
- Reset the timer on user interaction (click, keypress) so it doesn't refresh while active
- Show a small "Last refreshed: X minutes ago" indicator

### Better implementation:
```javascript
(function() {
  var timer = null;
  function scheduleRefresh() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(function() {
      if (document.visibilityState === 'visible' && !isFormDirty()) {
        location.reload();
      } else {
        scheduleRefresh(); // try again in 5 min
      }
    }, 300000);
  }
  function isFormDirty() {
    var inputs = document.querySelectorAll('input, textarea, select');
    for (var i = 0; i < inputs.length; i++) {
      if (inputs[i].type === 'hidden' || inputs[i].type === 'submit') continue;
      if (inputs[i].value !== inputs[i].defaultValue) return true;
    }
    return false;
  }
  scheduleRefresh();
  document.addEventListener('click', scheduleRefresh);
  document.addEventListener('keypress', scheduleRefresh);
})();
```

---

## EXECUTION PRIORITY

1. **Quote approval bug fix** — immediate, blocks real use
2. **Enable auto-create settings** — immediate, 1 SQL command
3. **Auto-refresh** — quick, 1 file change
4. **Full QA testing** — dispatch QA subagent to find all bugs
5. **Transactional email (SMTP)** — needed for reminders to work
6. **Hourly billing Phase 1** — manual hourly line items (UI only)
7. **Time tracking Phase 2** — new table + UI (larger feature)

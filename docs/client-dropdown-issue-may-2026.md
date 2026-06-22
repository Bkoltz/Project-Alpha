# Client Dropdown Initialization Issue — May 2026

## Problem
Client autocomplete dropdown was not appearing when typing in the client name field on:
- **Quote create page** — only worked on hard refresh, not SPA navigation
- **Contract create page** — didn't work at all
- **Invoice create page** — worked fine (reference point)

## Root Cause

### Primary Issue (Contracts & Quotes)
The `recalc()` and `recalcCo()` functions crashed with `TypeError: Cannot read properties of null (reading 'value')` because they tried to access `depositType`/`depositValue` elements that **did not exist in the HTML**.

**Execution flow that caused the crash:**
1. Script loads and starts executing
2. `addItem()` / `addItemCo()` is called at the bottom of the file
3. This calls `recalc()` / `recalcCo()`
4. `recalc()` crashes on: `document.getElementById('depositType').value`
5. **The entire script stops executing** — so `initQuoteClientDropdown()` / `initContractClientDropdown()` never runs
6. No event listener is attached to the client input field
7. Typing does nothing — dropdown never appears

### Secondary Issue (Quotes SPA Navigation)
The quotes page loaded **two** client dropdown initialization scripts:
- `quotes-create-logic.js` (with `initQuoteClientDropdown()`)
- `client-selection-dropdown-logic.js` (with `initClientDropdown()`)

Both targeted the same DOM elements (`clientInput`, `clientId`, `clientSuggest`). The `client-selection-dropdown-logic.js` had a `_clientDropdownInitialized` flag that could prevent re-initialization during SPA navigation.

## Why Invoice Worked
The invoice page had different HTML that included all elements `recalcInv()` expected. Its `initInvoiceClientDropdown()` was defined and called without any crashing code before it.

## Fixes Applied

### 1. Null-safe element access in recalc() functions
Changed from:
```javascript
var depType = document.getElementById('depositType').value;  // Crashes if null
```

To:
```javascript
var depositTypeEl = document.getElementById('depositType');
var depType = depositTypeEl ? depositTypeEl.value : 'none';  // Safe fallback
```

Also guarded deposit row display toggles with null checks.

### 2. Removed redundant script from quotes page
Removed `client-selection-dropdown-logic.js` from `quotes-create.php` since `quotes-create-logic.js` already had its own complete dropdown implementation (including project loading and tax banner).

### 3. Fixed client-selection-dropdown-logic.js initialization
Changed from using a `_clientDropdownInitialized` boolean flag (which blocked re-init on SPA nav) to using `removeEventListener` + `addEventListener` pattern that properly replaces old handlers.

### 4. Added multiple fallback timers
Added `setTimeout` fallbacks at 100ms and 500ms for SPA navigation timing issues.

## Prevention
When adding new form fields to a page:
1. Ensure all elements accessed in JavaScript `recalc()` or similar functions actually exist in the HTML
2. Use null-safe access: `var el = document.getElementById('id'); if (el) el.value`
3. Avoid calling calculation functions at the bottom of scripts if they depend on elements that may not exist
4. Don't load multiple scripts that initialize the same DOM elements — pick one authoritative initializer

## Related Files Modified
- `public/js/quotes-create-logic.js`
- `public/js/contracts-create-logic.js`
- `public/js/invoices-create-logic.js` (reference)
- `public/js/client-selection-dropdown-logic.js`
- `src/views/pages/quote/quotes-create.php`

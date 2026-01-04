# Phase 2 Bug Fixes

**Date**: January 3, 2026  
**Issues Fixed**: 2 critical bugs

## Bug #1: Settings Not Preserving Current Tab on Save ✅

### Problem
When saving settings on any tab (especially the new Taxes tab), users were redirected to the System tab instead of staying on the current tab/subtab.

### Root Cause
The `settings_handler.php` was hardcoded to redirect to `/?page=settings&saved=1` without preserving the `tab` and `doc_tab` parameters.

### Solution
Modified `src/controllers/settings_handler.php` to:
1. Extract `tab` and `doc_tab` from POST data
2. Append them to redirect URL
3. Applied to both successful save and fallback save paths

**Code Changes**:
```php
// Before:
header('Location: /?page=settings&saved=1');

// After:
$tab = $_POST['tab'] ?? '';
$docTab = $_POST['doc_tab'] ?? $_GET['doc_tab'] ?? '';
$redirect = '/?page=settings&saved=1';
if ($tab !== '') {
    $redirect .= '&tab=' . rawurlencode($tab);
}
if ($docTab !== '') {
    $redirect .= '&doc_tab=' . rawurlencode($docTab);
}
header('Location: ' . $redirect);
```

**Affected Locations**:
- Line 348 (main success redirect)
- Line 338 (fallback success redirect)

---

## Bug #2: Tax Rates Not Being Saved ✅

### Problem
Creating or editing tax rates resulted in no data being saved to the database. The form would submit but nothing would appear in the tax rates list.

### Root Causes
1. **Missing Routes**: The router (`public/index.php`) had no POST routes for:
   - `settings/tax-rates-handler`
   - `settings/custom-fields-handler`
   
   These handlers couldn't be reached, so forms posted to them went nowhere.

2. **Incorrect Include Paths**: `tax-rates-handler.php` had wrong relative paths:
   - Used `../../../config/db.php` (going up 3 levels)
   - Should be `../../config/db.php` (going up 2 levels from `controllers/settings/`)

### Solution

#### Part 1: Added Missing Routes
Modified `public/index.php` to add routes after the settings route:

```php
if ($page === 'settings') {
    require_once __DIR__ . '/../src/controllers/settings_handler.php';
    exit;
}
if ($page === 'settings/tax-rates-handler') {
    require_once __DIR__ . '/../src/controllers/settings/tax-rates-handler.php';
    exit;
}
if ($page === 'settings/custom-fields-handler') {
    require_once __DIR__ . '/../src/controllers/settings/custom_fields_handler.php';
    exit;
}
```

**Location**: Line 269-280

#### Part 2: Fixed Include Paths
Modified `src/controllers/settings/tax-rates-handler.php`:

```php
// Before:
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

// After:
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
```

**Location**: Lines 3-4

---

## Files Modified

1. **`src/controllers/settings_handler.php`**
   - Added tab/subtab preservation to redirects (2 locations)

2. **`public/index.php`**
   - Added POST routes for dedicated settings handlers

3. **`src/controllers/settings/tax-rates-handler.php`**
   - Fixed include path depths (going up 2 levels instead of 3)

---

## Testing Verification

### Tax Rates Tab:
- [x] Navigate to Settings → Taxes
- [x] Create new tax rate
- [x] Form saves successfully
- [x] Tax rate appears in table
- [x] User stays on Taxes tab after save
- [x] Edit existing tax rate works
- [x] Delete tax rate works
- [x] Set as default works

### All Settings Tabs:
- [x] System tab saves and stays on System
- [x] Terms tab saves and stays on Terms  
- [x] Billing tab saves and stays on Billing
- [x] Taxes tab saves and stays on Taxes
- [x] Documents → Quotes saves and stays on Documents → Quotes
- [x] Documents → Customization saves and stays on Documents → Customization
- [x] Notifications tab saves and stays on Notifications
- [x] Links tab saves and stays on Links

---

## Impact

### Before Fix:
- ❌ Tax rates couldn't be saved (complete blocker)
- ❌ Confusing UX: users redirected to System tab from all settings
- ❌ Loss of context when saving settings
- ❌ Custom fields handler also unreachable (though not noticed yet)

### After Fix:
- ✅ Tax rates save successfully
- ✅ Users stay on current tab/subtab after saving
- ✅ Better UX: no context switching
- ✅ All dedicated settings handlers now routable
- ✅ Phase 2 functionality fully operational

---

## Additional Notes

### Path Structure Clarification
The handlers are in: `src/controllers/settings/`
- From handler to `config`: Go up 2 levels (`../../`)
- Handler → controllers → src → config ✓

### Router Pattern
All dedicated handlers for specific tabs should be added to `public/index.php` POST section:
- `settings` → general settings
- `settings/tax-rates-handler` → taxes tab
- `settings/custom-fields-handler` → customization
- `settings/links_handler` → links tab (already existed)

This pattern should be followed for any future settings sub-handlers.

---

## Status
✅ Both bugs fixed and tested  
✅ Tax rates system fully functional  
✅ Settings navigation maintains state  
✅ Phase 2 complete and operational

# Document List Filter Migration to Twig

## Summary
Successfully migrated the document list filter component from PHP (`src/views/components/document_list_filter.php`) to a Twig template (`src/views/templates/components/document-filter.html.twig`). This resolves the path resolution errors users were experiencing.

## What Changed

### New Twig Template
- **File**: `src/views/templates/components/document-filter.html.twig`
- **Purpose**: Renders filterable form UI for document lists (quotes, contracts, invoices)
- **Features**:
  - Supports multiple filter types: text, date, number, select, client_autocomplete
  - Generates responsive grid layout
  - Includes client autocomplete with suggestions
  - Filter and reset buttons
  - Client-side JavaScript for autocomplete functionality

### Updated List Pages
All the following pages were updated to use Twig rendering instead of PHP includes:

#### Quote Pages
- `src/views/pages/quote/quotes-list.php`
- `src/views/pages/quote/long-term-quotes-list.php`
- `src/views/pages/quote/on-demand-quotes-list.php`

#### Contract Pages
- `src/views/pages/contract/contracts-list.php`
- `src/views/pages/contract/long-term-contracts-list.php`
- `src/views/pages/contract/on-demand-contracts-list.php`

#### Invoice Pages
- `src/views/pages/invoice/invoices-list.php`

### Changes Made to List Pages

1. **Added Twig import**:
   ```php
   require_once __DIR__ . '/../../../utils/twig.php';
   ```

2. **Converted filter config to proper array format**:
   - Changed select options from associative arrays (`'all' => 'All'`) to indexed arrays with value/label keys
   - This ensures compatibility with Twig's `attribute()` function

3. **Replaced PHP include with Twig render**:
   ```php
   // OLD:
   require __DIR__ . '/../../../components/document_list_filter.php';
   
   // NEW:
   echo render_template('components/document-filter.html.twig', $filterConfig);
   ```

## Why This Works Better

### Problem Solved
- **Path Resolution Error**: The relative path `require __DIR__ . '/../../../components/...'` was causing "Failed to open stream" errors when called from certain contexts
- **Path Independence**: Twig uses the configured template loader which always resolves paths from `src/views/templates/`

### Benefits
- **More Reliable**: Twig's loader is context-agnostic
- **Better Maintainability**: Filter logic is now in a dedicated Twig template
- **Consistent Architecture**: Uses the existing Twig infrastructure (functions already in `src/utils/twig.php`)
- **Future-Proof**: Easy to extend with additional Twig features (custom filters, loops, etc.)

## Testing
All updated pages should now:
1. Load without the "Failed to open stream" error
2. Display the filter form correctly
3. Support client autocomplete functionality
4. Properly filter documents based on user input

## Migration Notes
- The old PHP component file (`src/views/components/document_list_filter.php`) is no longer used but can be kept for reference
- No breaking changes to the filter functionality
- Filter configuration format remains essentially the same (converted select options to proper array format)

## Files Not Changed
The following list pages use custom filter implementations and were NOT modified:
- `src/views/pages/invoice/recurring-invoices-list.php` (custom form)
- `src/views/pages/invoice/on-demand-invoices-list.php` (custom form)

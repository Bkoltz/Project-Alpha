# Document List Filter Component

A reusable, standardized filter component for document list views across the application.

## Features

- **Consistent UI** - Same look and feel across all list pages
- **Easy to Learn** - Users only learn the interface once
- **Client Autocomplete** - Built-in AJAX client search with dropdown
- **Multiple Input Types** - Text, number, date, select, and autocomplete
- **Responsive Grid** - Automatically adjusts to number of filters
- **Zero JavaScript Duplication** - Autocomplete logic included in component

## Usage

### Basic Example

```php
<?php
$filterConfig = [
    'page' => 'quote/quotes-list',  // Required: the page parameter for form submission
    'filters' => [
        'client' => [
            'type' => 'client_autocomplete',
            'label' => 'Client',
            'value' => $client_name,
            'id_value' => $client_id
        ],
        'status' => [
            'type' => 'select',
            'label' => 'Status',
            'value' => $status,
            'options' => [
                'all' => 'All',
                'approved' => 'Approved',
                'pending' => 'Pending'
            ]
        ],
        'start' => [
            'type' => 'date',
            'label' => 'Start Date',
            'value' => $start
        ]
    ]
];

require __DIR__ . '/../components/document_list_filter.php';
?>
```

## Filter Types

### 1. Client Autocomplete
Auto-complete search for clients with AJAX dropdown.

```php
'client' => [
    'type' => 'client_autocomplete',
    'label' => 'Client',
    'value' => $client_name,      // Display value
    'id_value' => $client_id,     // Hidden ID field
    'placeholder' => 'Search...'  // Optional
]
```

**Submitted Fields:**
- `client` - The client name (text)
- `client_id` - The client ID (int)

### 2. Select Dropdown
Standard dropdown menu.

```php
'status' => [
    'type' => 'select',
    'label' => 'Status',
    'value' => $status,
    'options' => [
        'all' => 'All',
        'approved' => 'Approved',
        'rejected' => 'Denied'
    ]
]
```

### 3. Date Input
Date picker input.

```php
'start' => [
    'type' => 'date',
    'label' => 'Start Date',
    'value' => $start
]
```

### 4. Number Input
Numeric input with optional step.

```php
'min' => [
    'type' => 'number',
    'label' => 'Min Total ($)',
    'value' => $min,
    'step' => '0.01',        // Optional
    'placeholder' => '0.00'  // Optional
]
```

### 5. Text Input (Default)
Standard text input.

```php
'project_code' => [
    'type' => 'text',
    'label' => 'Project ID',
    'value' => $project_code,
    'placeholder' => 'PA-2025'
]
```

## Configuration Options

### Filter Config Structure

```php
$filterConfig = [
    'page' => 'required/page/path',  // Required
    'filters' => [...],               // Required: array of filters
    'columns' => 7                    // Optional: override grid columns
];
```

### Grid Layout

By default, the component calculates columns as: `number of filters + 2` (for Filter and Reset buttons).

Override with the `columns` parameter:

```php
$filterConfig = [
    'page' => 'quote/quotes-list',
    'columns' => 8,  // Force 8-column grid
    'filters' => [...]
];
```

## Complete Examples

### Quotes List
```php
$filterConfig = [
    'page' => 'quote/quotes-list',
    'filters' => [
        'client' => [
            'type' => 'client_autocomplete',
            'label' => 'Client',
            'value' => $client_name,
            'id_value' => $client_id
        ],
        'status' => [
            'type' => 'select',
            'label' => 'Status',
            'value' => $status,
            'options' => [
                'all' => 'All',
                'approved' => 'Approved',
                'rejected' => 'Denied',
                'pending' => 'Pending'
            ]
        ],
        'start' => [
            'type' => 'date',
            'label' => 'Start',
            'value' => $start
        ],
        'end' => [
            'type' => 'date',
            'label' => 'End',
            'value' => $end
        ],
        'project_code' => [
            'type' => 'text',
            'label' => 'Project ID',
            'value' => $project_code,
            'placeholder' => 'PA-2025'
        ],
        'doc_number' => [
            'type' => 'number',
            'label' => 'Doc #',
            'value' => $doc_no
        ]
    ]
];

require __DIR__ . '/../components/document_list_filter.php';
```

### Invoices List
```php
$filterConfig = [
    'page' => 'invoice/invoices-list',
    'filters' => [
        'client' => [
            'type' => 'client_autocomplete',
            'label' => 'Client',
            'value' => $client_name,
            'id_value' => $client_id
        ],
        'status' => [
            'type' => 'select',
            'label' => 'Status',
            'value' => $statusFilter,
            'options' => [
                'all' => 'All',
                'paid' => 'Paid',
                'unpaid' => 'Unpaid/Partial',
                'overdue' => 'Overdue'
            ]
        ],
        'min' => [
            'type' => 'number',
            'label' => 'Min Total ($)',
            'value' => $min,
            'step' => '0.01'
        ],
        'max' => [
            'type' => 'number',
            'label' => 'Max Total ($)',
            'value' => $max,
            'step' => '0.01'
        ],
        'project_code' => [
            'type' => 'text',
            'label' => 'Project',
            'value' => $project_code,
            'placeholder' => 'PA-2025'
        ],
        'doc_number' => [
            'type' => 'number',
            'label' => 'Doc #',
            'value' => $doc_no
        ]
    ]
];

require __DIR__ . '/../components/document_list_filter.php';
```

## Migration Guide

### Converting Existing List Pages

1. **Keep your existing filter variable extraction** (the `$_GET` parameters at the top)

2. **Replace the filter form HTML** with the `$filterConfig` setup

3. **Delete the inline autocomplete JavaScript** (it's now in the component)

### Before (quotes-list.php lines 45-85)
```php
<form method="get" action="/" style="display:grid;grid-template-columns:1fr 1fr...">
  <input type="hidden" name="page" value="quote/quotes-list">
  <input type="hidden" name="client_id" id="clientIdQL"...>
  <label style="position:relative"><div>Client</div>
    <input type="text" name="client" id="clientInputQL"...>
    <div id="clientSuggestQL" style="position:absolute..."></div>
  </label>
  <!-- More filter fields... -->
  <button type="submit">Filter</button>
  <a href="...">Reset</a>
</form>

<script>
  // 40 lines of autocomplete JavaScript
</script>
```

### After
```php
<?php
$filterConfig = [
    'page' => 'quote/quotes-list',
    'filters' => [
        'client' => [
            'type' => 'client_autocomplete',
            'label' => 'Client',
            'value' => $client_name,
            'id_value' => $client_id
        ],
        // More filters...
    ]
];

require __DIR__ . '/../components/document_list_filter.php';
?>
```

## Benefits

### For Developers
- ✅ **60+ lines** of HTML/JS reduced to **~10 lines** of config
- ✅ No duplicate autocomplete code across pages
- ✅ Consistent styling automatically
- ✅ Easy to add/remove filters
- ✅ Centralized bug fixes

### For Users
- ✅ Same interface across all list pages
- ✅ Learn once, use everywhere
- ✅ Consistent keyboard shortcuts
- ✅ Predictable behavior

## Technical Details

### Client Autocomplete
- Uses `/page=clients-search` endpoint
- Expects JSON response: `[{id: 1, name: "Client Name"}, ...]`
- Submits both `client` (name) and `client_id` (ID)
- Auto-clears ID when typing new name
- Closes on outside click

### Unique IDs
Each filter instance gets unique DOM IDs using `md5($page . microtime())` to prevent conflicts when multiple filters exist on the same page.

### Grid Layout
Uses CSS Grid with equal-width columns. Filters automatically wrap on smaller screens based on CSS Grid's responsive behavior.

## Pages to Migrate

- [ ] `quote/quotes-list.php`
- [ ] `quote/long-term-quotes-list.php`
- [ ] `quote/on-demand-quotes-list.php`
- [ ] `invoice/invoices-list.php`
- [ ] `invoice/recurring-invoices-list.php`
- [ ] `invoice/on-demand-invoices-list.php`
- [ ] `contract/contracts-list.php`
- [ ] `contract/long-term-contracts-list.php`
- [ ] `contract/on-demand-contracts-list.php`
- [ ] `payments/payments-list.php`
- [ ] Any other list pages with filters

See `quotes-list-refactored-example.php` for a complete working example.

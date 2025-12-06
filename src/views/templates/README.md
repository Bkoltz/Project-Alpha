# Twig Templates Guide

This directory contains reusable Twig templates to help standardize and simplify the Project Alpha UI.

## Setup

The Twig environment is automatically configured when you include `src/utils/twig.php`.

## Directory Structure

```
templates/
├── layouts/          # Base layouts
│   └── base.html.twig
├── components/       # Reusable components
│   └── list-view.html.twig
└── README.md
```

## Usage

### 1. Basic Template Rendering

```php
<?php
require_once __DIR__ . '/../../utils/twig.php';

// Render and display
display_template('my-template.html.twig', [
    'title' => 'My Page',
    'data' => $someData
]);

// Or get rendered HTML
$html = render_template('my-template.html.twig', ['key' => 'value']);
```

### 2. Using the List View Component

The list view component provides a standardized interface for all list pages (quotes, contracts, invoices, clients, etc.).

**Example:**

```php
<?php
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/twig.php';

// Fetch your data
$items = $pdo->query("SELECT * FROM quotes")->fetchAll(PDO::FETCH_ASSOC);

// Define columns
$columns = [
    ['key' => 'doc_number', 'label' => 'Quote #', 'format' => 'text'],
    ['key' => 'client_name', 'label' => 'Client', 'format' => 'text'],
    ['key' => 'total', 'label' => 'Amount', 'format' => 'money'],
    ['key' => 'created_at', 'label' => 'Date', 'format' => 'date'],
    ['key' => 'status', 'label' => 'Status', 'format' => 'status'],
];

// Define actions (buttons)
$actions = [
    ['label' => 'View', 'url_key' => 'view_url', 'class' => 'btn'],
    ['label' => 'Edit', 'url_key' => 'edit_url', 'class' => 'btn-primary'],
];

// Render
display_template('components/list-view.html.twig', [
    'title' => 'Quotes',
    'items' => $items,
    'columns' => $columns,
    'actions' => $actions,
    'create_url' => '/?page=quote/quotes-create',
    'create_label' => 'Create Quote',
    'search_placeholder' => 'Search quotes...',
]);
```

### 3. List View Parameters

| Parameter | Type | Required | Description |
|-----------|------|----------|-------------|
| `title` | string | Yes | Page title (e.g., "Quotes", "Clients") |
| `items` | array | Yes | Array of items to display |
| `columns` | array | Yes | Column definitions (see below) |
| `actions` | array | No | Action buttons for each row |
| `create_url` | string | No | URL for "Create New" button |
| `create_label` | string | No | Label for create button (default: "Create New") |
| `search_placeholder` | string | No | Placeholder text for search field |
| `filters` | array | No | Filter dropdown options |

### 4. Column Definition

Each column is an array with:

```php
[
    'key' => 'field_name',        // Field name from your data array
    'label' => 'Column Header',   // Display name for column header
    'format' => 'text|money|date|status'  // How to format the value
]
```

**Available Formats:**
- `text` - Display as-is
- `money` - Format as currency ($1,234.56)
- `date` - Format as date (Jan 15, 2024)
- `status` - Display as colored status badge

### 5. Action Definition

Each action button is an array with:

```php
[
    'label' => 'Edit',           // Button text
    'url_key' => 'edit_url',     // Key in your item array containing the URL
    'class' => 'btn-primary'     // CSS class (optional)
]
```

**Note:** Your SQL query should include the URL fields:

```sql
SELECT 
    id,
    name,
    CONCAT('/?page=quotes-edit&id=', id) as edit_url,
    CONCAT('/?page=quotes-view&id=', id) as view_url
FROM quotes
```

### 6. Custom Twig Filters

Available in all templates:

- `{{ value|money }}` - Format as currency
- `{{ date|date_format }}` - Format date (default: 'M j, Y')
- `{{ date|date_format('Y-m-d') }}` - Custom date format

### 7. Custom Twig Functions

Available in all templates:

- `{{ csrf_token() }}` - Get CSRF token
- `{{ url('page-name', {'param': 'value'}) }}` - Generate URL

### 8. Global Variables

Available in all templates:

- `app` - Application config array
- `user` - Current logged-in user (if authenticated)

## Examples

See `src/views/pages/client/clients-list-twig.php` for a complete working example.

## Converting Existing Pages

To convert an existing list page to use Twig:

1. Replace the HTML with Twig template call
2. Define columns array
3. Define actions array  
4. Call `display_template()`

**Before:**
```php
<section>
  <h2>Quotes</h2>
  <table>
    <thead>
      <tr>
        <th>Quote #</th>
        <th>Client</th>
        <!-- ... -->
      </tr>
    </thead>
    <tbody>
      <?php foreach ($quotes as $q): ?>
        <tr>
          <td><?php echo $q['doc_number']; ?></td>
          <!-- ... -->
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</section>
```

**After:**
```php
<?php
require_once __DIR__ . '/../../../utils/twig.php';

display_template('components/list-view.html.twig', [
    'title' => 'Quotes',
    'items' => $quotes,
    'columns' => [ /* ... */ ],
    'actions' => [ /* ... */ ],
]);
```

Much cleaner!

## Best Practices

1. **Keep logic out of templates** - Do data processing in PHP, not in Twig
2. **Reuse components** - Use existing components when possible
3. **Consistent naming** - Use consistent column keys across similar pages
4. **Document custom templates** - Add comments explaining parameters
5. **Test with empty data** - Always test how your template handles no results

## Next Steps

Consider creating additional reusable components:
- Form builder component
- Detail/view page component
- Modal/dialog component
- Notification/alert component

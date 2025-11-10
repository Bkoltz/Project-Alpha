# Templating Strategy

## Current Approach: PHP Templates

We use **pure PHP templates** with inline HTML. This is simple, performant, and requires no additional dependencies.

## File Organization

```
src/views/
├── pages/           # Full page views
│   ├── contract/
│   ├── invoice/
│   └── client/
├── partials/        # Layout components (header, footer)
└── components/      # Reusable UI components
```

## When to Extract Components

Create a new component file when:
- ✅ Code is repeated 3+ times across pages
- ✅ Logic is complex and self-contained
- ✅ You want to A/B test variations
- ✅ Component could be reused across contexts

## Component Pattern

### Creating a Component

```php
// src/views/components/alert_box.php
<?php
$type = $alert['type'] ?? 'info'; // info, success, warning, error
$message = $alert['message'] ?? '';
$colors = [
    'info' => ['bg' => '#eff6ff', 'border' => '#3b82f6', 'text' => '#1e40af'],
    'success' => ['bg' => '#ecfdf5', 'border' => '#10b981', 'text' => '#065f46'],
    'warning' => ['bg' => '#fef3c7', 'border' => '#f59e0b', 'text' => '#92400e'],
    'error' => ['bg' => '#fef2f2', 'border' => '#ef4444', 'text' => '#991b1b'],
];
$c = $colors[$type];
?>
<div style="padding:12px;border-radius:8px;background:<?php echo $c['bg']; ?>;border:1px solid <?php echo $c['border']; ?>;color:<?php echo $c['text']; ?>">
    <?php echo htmlspecialchars($message); ?>
</div>
```

### Using a Component

```php
<?php
$alert = ['type' => 'success', 'message' => 'Contract created!'];
include __DIR__ . '/../components/alert_box.php';
?>
```

## Future: Twig Consideration

### When to Switch to Twig

Consider Twig when you reach any of these thresholds:

1. **50+ page templates** (complexity management)
2. **Multiple developers** (need stronger separation)
3. **Designer collaboration** (non-PHP team members)
4. **Template caching needs** (high-traffic sites)

### Migration Strategy

If switching to Twig:

1. **Install Twig**: `composer require twig/twig`
2. **Create base layout**: `templates/base.twig`
3. **Migrate incrementally**: Start with new pages
4. **Keep PHP partials**: No need to migrate header/footer immediately
5. **Use Twig for new features**: Adopt gradually

### Twig Example

```twig
{# templates/pages/contracts/list.twig #}
{% extends 'base.twig' %}

{% block title %}Contracts{% endblock %}

{% block content %}
<section>
  <h2>Contracts</h2>
  <table>
    {% for contract in contracts %}
      <tr>
        <td>{{ contract.doc_number }}</td>
        <td>{{ contract.client_name }}</td>
        <td>${{ contract.total|number_format(2) }}</td>
      </tr>
    {% endfor %}
  </table>
</section>
{% endblock %}
```

## Performance Considerations

### Current PHP Templates
- ✅ **Fast**: No parsing overhead
- ✅ **Simple**: Direct execution
- ✅ **Flexible**: Full PHP power available
- ❌ **Verbose**: More boilerplate code
- ❌ **Mixing concerns**: Logic and presentation together

### Twig Templates
- ✅ **Clean syntax**: Less boilerplate
- ✅ **Auto-escaping**: Better security
- ✅ **Inheritance**: DRY layouts
- ❌ **Slight overhead**: ~5-10ms per page (cached)
- ❌ **Learning curve**: New syntax to learn

## Recommendation

**Current verdict: Stick with PHP templates**

Reasons:
- App size is manageable (~30 pages)
- Performance is excellent
- Team knows PHP
- No designer collaboration needed yet
- Simple to debug and maintain

**Re-evaluate when:**
- You have 50+ templates
- You hire a dedicated frontend developer
- Template logic becomes too complex
- You need advanced caching strategies

## Best Practices (Current PHP Approach)

1. **Escape all output**: `htmlspecialchars()` everywhere
2. **Extract repeated HTML**: Use components
3. **Keep logic light**: Heavy logic in controllers
4. **Use partials**: header.php, footer.php, etc.
5. **Consistent naming**: `snake_case.php` for files
6. **Document components**: Add usage examples in comments

## Example Refactor

### Before (Repeated Code)
```php
<!-- contracts-list.php -->
<div style="padding:12px;background:#ecfdf5;border:1px solid #10b981">Success!</div>

<!-- invoices-list.php -->
<div style="padding:12px;background:#ecfdf5;border:1px solid #10b981">Invoice created!</div>
```

### After (Component)
```php
<!-- contracts-list.php -->
<?php 
$alert = ['type' => 'success', 'message' => 'Success!'];
include __DIR__ . '/../components/alert_box.php';
?>

<!-- invoices-list.php -->
<?php 
$alert = ['type' => 'success', 'message' => 'Invoice created!'];
include __DIR__ . '/../components/alert_box.php';
?>
```

## Decision Tree

```
Need templating improvement?
│
├─ Is code repeated 3+ times?
│  ├─ Yes → Extract to component (PHP include)
│  └─ No → Keep inline
│
├─ Need designer-friendly syntax?
│  ├─ Yes → Consider Twig
│  └─ No → Use PHP templates
│
├─ Have 50+ templates?
│  ├─ Yes → Strongly consider Twig
│  └─ No → PHP is fine
│
└─ Need advanced caching?
   ├─ Yes → Twig with caching
   └─ No → PHP with op-cache is sufficient
```

## Conclusion

**For this project**: Continue with PHP templates, extract common patterns to components, and revisit Twig when the app grows significantly.

The current approach is pragmatic, performant, and maintainable for the current scale.

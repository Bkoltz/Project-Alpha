# Templating Strategy

## Current Approach: PHP Templates + Twig Migration

We use **PHP templates** with inline HTML, with an ongoing migration to **Twig** for reusable components and new pages.

## What's Been Done (June 2026)

### CSS Utility Classes Added
70+ utility CSS classes added to `public/assets/styles.css`:
- `.input`, `.input-sm`, `.label`, `.field`, `.field-row` — form elements
- `.btn`, `.btn-sm`, `.btn-lg`, `.btn-primary` — buttons
- `.card`, `.card-tight`, `.card-head`, `.card-title` — cards
- `.pa-table`, `.pa-table th`, `.pa-table td`, `.pa-table-wrap` — tables
- `.status-pill`, `.status-pill--{status}` — status badges
- `.grid`, `.grid-2`, `.grid-3`, `.grid-4`, `.flex`, `.flex-end`, `.flex-between` — layout
- `.muted`, `.text-sm`, `.text-right`, `.font-600`, `.page-head` — utilities
- `.alert`, `.alert-success`, `.alert-danger`, `.alert-warning` — alerts

### Inline Style Reduction
- 545+ inline `style="..."` attributes replaced with CSS classes across 34+ templates
- Remaining inline styles are document-specific (PDF layout, contract formatting, signature blocks)

### Twig Components Available
- `src/views/templates/layouts/base.html.twig` — base HTML layout
- `src/views/templates/components/list-view.html.twig` — reusable list/table view
- `src/views/templates/components/document-filter.html.twig` — reusable filter bar
- `src/utils/twig.php` — Twig environment setup with custom filters (money, date_format) and functions (csrf_token, url)

## File Organization

```
src/views/
├── pages/              # Full page views (PHP templates, migrating to Twig)
│   ├── contract/
│   ├── invoice/
│   ├── quote/
│   ├── client/
│   ├── settings/
│   └── ...
├── partials/           # Layout components (header, footer)
├── templates/          # Twig templates
│   ├── layouts/        # Base layouts
│   └── components/     # Reusable Twig components
└── public/             # Public document templates (Twig)
```

## Migration Strategy

### Phase 1: CSS Cleanup (DONE)
- Add utility CSS classes
- Replace repeated inline styles with classes
- No logic changes, purely presentation

### Phase 2: List Pages to Twig (NEXT)
- Migrate list pages to use `list-view.html.twig` component
- Move PDO queries from view templates to controllers
- Pages: quotes-list, contracts-list, invoices-list, clients-list, payments-list, etc.

### Phase 3: Detail Pages to Twig
- Convert contract-details, invoice-details, quote-details to Twig
- Extract document layout into a reusable Twig template
- Handle PDF mode rendering in Twig

### Phase 4: Form Pages to Twig
- Convert create/edit forms to Twig
- Extract form field components
- Standardize form validation display

## Best Practices

1. **New pages use Twig**: All new pages should use Twig templates
2. **Escape all output**: `htmlspecialchars()` in PHP, `{{ var }}` in Twig (auto-escaped)
3. **Use CSS classes**: No new inline styles — use utility classes or add new ones to styles.css
4. **Keep logic in controllers**: PDO queries belong in controllers, not view templates
5. **Use Twig components**: Reuse `list-view.html.twig` and `document-filter.html.twig` for list pages
6. **Document components**: Add usage examples in comments
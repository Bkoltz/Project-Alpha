# Developer and Agent Guidance

These instructions apply to contributors and coding agents working in Project Alpha.

## Before Changing Code

1. Read the root `CONTEXT.md`.
2. Read `docs/DOCUMENT_WORKFLOW.md` for document-related changes.
3. Inspect the current implementation instead of relying on historical plans or audit files.
4. Check `git status` and preserve unrelated work.
5. Search for an existing public GitHub issue or create one for a reproducible bug.

## Scope and Safety

- The local checkout and local Docker stack are test environments unless explicitly stated otherwise.
- Never connect to or mutate production without specific authorization.
- Never commit credentials, encryption keys, private host information, production logs, or customer data.
- Use Stripe test mode for payment verification.
- Treat legal, tax, accounting, surcharge, and compliance statements as requiring independent review.

## Build and Test

```bash
# Build the current checkout
docker compose -f docker-compose.yml -f docker-compose.override.yml up -d --build

# Install dependencies and run PHPUnit
composer install
composer test

# Validate pending migrations without applying them
docker compose -f docker-compose.yml -f docker-compose.override.yml run --rm migrate \
  php /var/www/src/migrations/run_migrations.php --dry-run --verbose
```

The production image uses PHP 8.5. Composer resolves dependencies against PHP 8.3.31 and permits PHP 8.1 or newer.

## Critical Data Rules

### Unified document tables

Use the existing type columns:

- `quotes.quote_type`
- `contracts.contract_type`
- `invoices.invoice_type`

Allowed values are `regular`, `long_term`, and `on_demand`. Do not recreate legacy type-specific tables or boolean type flags.

### Schema changes

Until `0.5.0-rc1` is frozen, schema refactors may be folded directly into `database/baseline.sql` because there is no supported upgrade path from old dev schemas. Once 0.5.0 is frozen or shipped, every schema change must update both:

1. `database/baseline.sql` for fresh installs
2. A new idempotent file in `database/migrations/` for existing databases

Use nullable columns or safe defaults for upgrade migrations. Test fresh installation and upgrade paths. Rollback files are not allowed in the forward migration directory.

### Migrations on boot

The one-shot `migrate` service initializes an empty schema from `database/baseline.sql`, runs the tracked migration runner, and must complete before web or cron starts. Migration errors are startup-blocking.

## Document Behavior

- Authenticated quote approval respects the automatic contract and invoice settings.
- Public quote approval creates the matching contract and creates an invoice only for regular quotes.
- Regular contract creation creates an unpaid invoice.
- Signed regular contracts activate automatically.
- Signed long-term and on-demand contracts require explicit activation in the authenticated application.
- Long-term invoices are scheduled; on-demand invoices are manually generated.
- Contract void and re-enable operations affect related invoices and public links.
- Payments are recorded through payment rows; do not directly flip invoice status without reconciliation.

Changes across quote, contract, invoice, public-link, email, or payment flows should be verified end to end.

## Routing

All requests enter through `public/index.php` and use the `page` query parameter. Public routes, CSRF exceptions, authentication rules, and controller mappings live there.

When adding a route:

- Add the narrowest required public/authentication exception.
- Keep state-changing requests protected by CSRF or a verified webhook signature.
- Add ACL permission mapping for authenticated functionality.
- Verify both direct navigation and asynchronous navigation behavior.

## Authorization

Project Alpha uses system roles, role permissions, organization memberships, and per-user overrides. Use helpers in `src/utils/acl.php` and existing middleware patterns. Do not authorize solely by hiding navigation links.

## Uploads and Public Links

- Use the shared upload validator and real MIME inspection.
- Store uploads only in mounted upload directories.
- Serve uploads through the controlled upload route.
- Apply size limits and safe generated filenames.
- Validate public-link token, type, expiry, revocation, and document status before accepting an action.

## Payments

- Use `PaymentProcessorInterface` and the Stripe implementation rather than embedding processor calls in views.
- Make webhook handlers idempotent.
- Recalculate payment totals from successful payment rows.
- Preserve Stripe metadata used for invoice reconciliation.
- Never log secrets, raw card information, or unnecessary payment payload data.

## Templates and Browser Code

The application currently mixes PHP views and Twig components. Follow the local pattern of the feature being changed rather than performing unrelated template migrations.

- Shared styles: `public/assets/styles.css`
- Page JavaScript: `public/js/`
- Twig templates: `src/views/templates/`
- PHP pages: `src/views/pages/`

User-facing changes should be browser-tested at normal and narrow widths. PDF changes must also be rendered and inspected.

## Verification by Risk

| Change | Minimum verification |
|---|---|
| Documentation | Markdown structure, commands, and links |
| PHP-only logic | `php -l` and relevant PHPUnit tests |
| Schema | Migration dry run, fresh install, and upgrade test |
| UI | Browser workflow and responsive check |
| Public link | Expiry, revocation, CSRF/rate limit, and success path |
| Payment | Stripe test mode, webhook, reconciliation, and duplicate delivery |
| Cron | Manual script run, `cron_job_runs`, and logs |

## Pull Requests

- `main` is protected and requires a pull request.
- Keep fixes narrow and reference the public issue.
- Explain behavior changes, tests performed, migration impact, and operator follow-up.
- Do not claim a bug is fixed without reproducing the original failure and verifying the corrected path.

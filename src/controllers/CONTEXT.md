# Controller Context

Last reviewed: 2026-06-28

Controllers handle routed HTTP actions, database writes, redirects, JSON responses, PDFs, uploads, public-link actions, and webhooks. Routing and middleware decisions are centralized in `public/index.php`.

## Areas

- `auth/`, `account/`, `accounts/`: login, 2FA, password reset, profiles, and user administration
- `client/`, `organization/`, `project/`: business entities and grouping
- `quote/`, `contract/`, `invoice/`: document CRUD and lifecycle actions
- `financial/`: expenses, receipts, mileage, vendors, imports, reports, and audits
- `public_view/`: tokenized client document interactions
- `settings/`: tax, links, custom fields, items, permissions, and logs
- `stripe/`, `webhook/`: Checkout, Payment Intents, reconciliation events, and compatibility handlers
- `time-tracking/`: timers, entries, and unbilled work

## Rules

- Use the authentication, CSRF, ACL, and ownership patterns established by the router and `src/utils/acl.php`.
- Keep public actions rate-limited and validate token type, expiry, revocation, and current document status.
- Use unified document tables with `quote_type`, `contract_type`, and `invoice_type`.
- Keep controllers thin when reusable business logic belongs in a service or utility.
- Use transactions for related writes while remembering that MySQL DDL is not transactional.
- Redirect with safe user messages and log technical context without credentials or personal data.
- Never trust hidden form fields for organization, ownership, price, or payment authorization without server-side validation.

Read `docs/DOCUMENT_WORKFLOW.md` before changing quote, contract, invoice, public-link, or payment actions.

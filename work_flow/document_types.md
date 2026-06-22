# Document Types (Overview)

This document explains the document types Project Alpha supports, the common lifecycles, and how related behaviors (PDFs, emails, and uploads) work.

## 1. Quotes
- Purpose: A proposal for services or products offered to a client.
- Statuses: `draft`, `pending`, `approved`, `denied`, `archived`.
- Key behaviors:
  - When a quote is `approved`, Project Alpha will automatically generate a related Contract and Invoice (unless the `auto-create` setting is disabled).
  - Quotes are often the origin point for work — they trigger creation of contracts/invoices and are then locked (unedible) on approval.
  - Quotes can be viewed in a public link via `public-quote`/`public-doc` routes.

## 2. Contracts
- Purpose: Legal agreement representing the scope and terms between the vendor and client.
- Statuses: `pending`, `active`, `complete`, `cancelled`, `void`, `archived`.
- Key behaviors:
  - A Contract may be created directly or generated after a quote approval.
  - Uploading a signed PDF: Uses the `contract_sign.php` controller which stores signed PDFs under `src/uploads` and records a `signed_pdf_path` field on the `contracts` table.
  - When a signed contract is uploaded, status moves to `active`; when work is finished, status can be changed to `complete`.
  - Contracts may be long-term or on-demand; scheduled invoices are generated based on contract configuration.

## 3. Invoices
- Purpose: Billing documents related to a Contract or Quote.
- Statuses: `unpaid`, `partial`, `paid`, `void`, `archive`.
- Key behaviors:
  - Invoices can be generated automatically from quotes or contracts and may be recurring or one-time.
  - Invoice payments can be recorded and may set the contract to `complete` depending on payment rules.
  - Reminders and scheduled invoicing are managed by the `cron` tasks.

## 4. Long-term and On-Demand Document Variants

Document types are stored in type columns on the unified tables:
- `quotes.quote_type`: `regular`, `long_term`, `on_demand`
- `contracts.contract_type`: `regular`, `long_term`, `on_demand`
- `invoices.invoice_type`: `regular`, `on_demand`

**Important**: The dev branch does NOT use separate `long_term_contracts` or `on_demand_contracts` tables. All documents are in the unified `quotes`/`contracts`/`invoices` tables with a type filter. When restoring files from the main branch, SQL queries MUST be patched to use these type columns instead of the legacy `is_long_term`/`is_on_demand` booleans or separate tables.

- Long-term: Documents associated with long-term contracts (recurring invoices, series of invoices). Uses `contract_type='long_term'` filter and `billing_interval_count`/`billing_interval_unit` columns.
- On-demand: Documents generated ad-hoc for one-off work or change orders. Uses `contract_type='on_demand'` / `invoice_type='on_demand'` filter.

## 5. Public Views & PDFs
- Public view pages (eg: `public-doc`) allow clients to view and accept or interact with documents.
- PDF generation uses Dompdf. Relevant controllers (e.g., `contract_pdf.php`, `invoice_pdf.php`, `quote_pdf.php`) set `PDF_MODE` and include the corresponding view templates, then stream the PDF to the browser with appropriate PDF headers (`Content-Type: application/pdf`).
- When generating PDFs, the code sets a chroot and base path to `public/` so that local assets (such as logo files) can be embedded in the documents.

## 6. Uploads and Serving Files
- Signed contract PDF uploads are saved under `src/uploads` (so they can be mounted via Docker volume). The `serve_upload.php` controller finds and serves files securely.
- The code includes user-facing `signed_pdf_path` fields on the `contracts` table to reference publicly accessible routes for viewing or downloading uploaded documents.

## 7. Email Integration
- Email sending is handled by `email_send.php` and may attach PDFs (composed/rendered with Dompdf).
- The system may generate links to public documents in emails for client interaction.

## 8. Notes & Best Practices
- When a Quote is `approved`, system-generated Contract/Invoice relationships help maintain traceability; use `project_code` whenever possible to group related documents.
- Keep CORS, file serving, and environment configuration in mind when deploying to production.

***

For more detailed lifecycle examples and edge-case handling, see `work_flow/regular_docs.md` and `work_flow/long-term_docs.md`.

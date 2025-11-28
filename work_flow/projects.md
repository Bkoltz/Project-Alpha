# Jobs (project_code) and Projects

Project Alpha supports two distinctly related concepts:

- **Jobs (project_code)** — auto-generated short codes that group related documents (quotes, contracts, invoices) and are commonly used for recurring or single-scope work.
- **Projects (manual)** — a new parent entity (stored in the `projects` table) that lets you manually organize Jobs and documents across larger initiatives or client programs.

## What is a Project Code
- `project_code` is a short string (VARCHAR(64)) used as a foreign key to categorize documents into a single project (quotes, contracts, invoices and long-term entries).
- The code is saved on various tables (quotes, contracts, invoices, recurring invoices, long-term contracts) and is indexed for fast queries.

## How to use Project Codes
- When creating a new Quote/Contract/Invoice, set the `project_code` field to tie it to the project (e.g., `ACME-Website-2025`).
- Project metadata is stored in `project_meta`. It can hold `notes`, `client_id`, and `terms` specific to the project.
- When creating an invoice, the code will insert or update `project_meta` using:
  ```php
  $up = $pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
  ```

## Projects vs Jobs mapping
- **Projects** (manual) are stored in `projects` table; they can be nested and can hold meta and notes for the larger initiative.
- **Jobs** (the `project_code` value) are typically auto-generated and link documents together; however Jobs can be associated with a Project using the `project_documents` mapping or by updating the `project_id` column on a document.

## Projects and Document Generation
- When a quote is approved or a contract is generated, the application will propagate the `project_code` to the related documents.
- Project-level logic can adjust templates, terms, or schedule recurring invoices based on `project_meta` settings.

## Long-term vs On-Demand
- Long-term projects often use recurring invoices or schedules — they frequently use `long_term_contracts` and `recurring_invoices` tables.
- On-demand projects are shorter-lived one-off jobs that are primarily represented by a single contract and corresponding invoice.

## Best Practices
- Choose a descriptive but short `project_code` to identify projects consistently (e.g. "ACME-Q3-Website").
- Maintain project metadata where necessary to ensure the correct terms, client references and invoice generation schedule.
- Use `project_code` as an audit-friendly way to collate documents across clients and contracts for reporting.

***

For sample SQL and schema references, see `database/migrations/000_all.sql` where `project_code` is defined and indexed on the documents tables.

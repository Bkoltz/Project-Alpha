# Logging
- Implement structured, and rotating logs capturing user, system, and security events.

- Logging Goals
  - Maintain an audit trail for critical actions (document creation/changes, payments, role changes).
  - Use structured logs (JSON lines) for easier parsing and analysis.
  - Rotate and retain logs safely: rotate at 10MB, keep last 30 files, archive older logs to cold storage.

- What to log (minimum):
  - User actions
    - Document creation (quote/contract/invoice) with `document_type`, `doc_id`, `user_id`, `ip`, `changes`.
    - Status transitions (old/new state) with actor and timestamp.
    - Edits to critical financial fields: `discount`, `tax_percent`, `total`, with before/after values.
    - File uploads/downloads (signed PDFs, deliverables) with file path and user.
  - System events
    - Scheduled jobs start/finish/errors (recurring invoice generation, auto-termination).
    - Sync tasks results (success/failure), external sync errors.
    - API interactions (Stripe webhooks, email gateway calls) with correlation IDs.
    - Unhandled exceptions: record minimal stack-trace ID and link to error logging collection (not full dumps in logs).
  - Security
    - Failed logins, successful logins, password resets, permission denials, role changes.

- Storage / Rotation
  - Use Monolog (PHP) with `RotatingFileHandler` or `StreamHandler` + `BufferHandler` to write JSON-lines logs.
  - Rotate on size (10MB), keep last 30 files locally, and export older files to archival storage (S3/TrueNAS) every week.
  - Optionally, write a subset of critical audit events to a `system_audit` DB table for quicker queries (see schema below).

- Sample `system_audit` table (optional) — add to DB migrations if desired:

```sql
CREATE TABLE IF NOT EXISTS system_audit (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  level VARCHAR(16) NOT NULL,
  category VARCHAR(64) NOT NULL,
  actor_type VARCHAR(32) NULL,
  actor_id INT NULL,
  ip VARCHAR(45) NULL,
  message TEXT NULL,
  payload JSON NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_category (category),
  INDEX idx_audit_actor (actor_type, actor_id),
  INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- Log Format (log line example):
`{"ts":"2025-12-10T14:22:11Z","level":"info","category":"contract","actor":{"id":3,"ip":"172.16.18.1"},"action":"status_change","details":{"id":42,"old":"pending","new":"active"}}`

- Implementation notes
  - Ensure critical audit events are read-only once written (no UI editing). If updates are required, append compensating entries rather than mutating original log lines.
  - Use a short stack-trace ID in logs for exceptions and store full traces only in secure error-tracking (Sentry) if available.
  - Add logging helpers in `utils/` to standardize message format and to wrap Monolog usage.

# SQL database changes
- beause the system doesn't use negative numbers for doc increment or anything that uses int/bigint, we should make them all unsigned int or unsigned big int to give us a higher top end.

# Templating
We have added a reusable Twig component `src/views/templates/components/document-filter.html.twig` and wired it into Quotes, Contracts, and Invoices list views to provide consistent filtering.

# Detail Pages
- Currently `*-details.php` we need to add a button to the right of the "View PDF" button that says "Download" which auto matically downloads the PDF to the clients computer.

Note: Added `Download` buttons next to `View PDF` across detail and job pages to prompt file download (same-origin).

# Settings
## Terms & Conditions
- Move "Documents Valid for" to Documents -> Customization.
- Move the "Show terms on quotes" to Documents -> Quotes
- Add Terms and conditions field for On-Demand type documents
## Notifications
- Recurring invoice generation option should be under the Documents -> invoice 
- Contract Auto-termination option should be under Documnets -> contracts
## Billing & Taxes
- Rename interface label to **Billing & Taxes**.
- Add `tax_rates` table to DB to store predefined tax rates per jurisdiction with columns:
    - `id INT PK`, `name VARCHAR`, `country VARCHAR`, `state VARCHAR` (optional), `county VARCHAR` (optional), `rate DECIMAL(5,2)`, `is_active TINYINT`, `created_at TIMESTAMP`.
- Add columns on `invoices` for `tax_amount DECIMAL(12,2)` and `tax_county VARCHAR(100)` so audits can include actual tax charged and county info.
- UX behavior:
    - When creating documents, allow selecting a predefined tax rate from `tax_rates` (filters by country/state/county as available).
    - If no predefined rate applies, allow entering a manual tax percent (free-form percent input).
    - Display computed `tax_amount` on the document preview and persist `tax_amount` and `tax_county` on save (so audit records show exact charged tax).
- Admin settings:
    - CRUD UI for `tax_rates`, marking defaults per country or organization.
    - Option to enable/disable county-level selection (useful for non-US orgs).
- DB migration example (already added to main migration):

```sql
CREATE TABLE IF NOT EXISTS tax_rates (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  country VARCHAR(100) NOT NULL DEFAULT 'USA',
  state VARCHAR(100) NULL,
  county VARCHAR(100) NULL,
  rate DECIMAL(5,2) NOT NULL DEFAULT 0,
  is_active TINYINT(1) DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_tax_country (country),
  INDEX idx_tax_state (state),
  INDEX idx_tax_county (county)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```
- Implementation notes:
    - When saving a document, if a `tax_rate` is selected, populate `tax_percent` and compute/persist `tax_amount` and (if applicable) `tax_county` into the document row.
    - Keep backward compatibility: if `tax_percent` is present but no `tax_rate` selected, compute `tax_amount` from that percent.
    - Update existing reports / exports to read `tax_amount` and `tax_county` when available.

  Note: `tax_rates` table already exists in `database/migrations/000_all.sql`. Added a basic CRUD UI under Settings → Billing and a handler `src/views/pages/settings/tax-rates-handler.php`.


## Documents
- Right now when a document is voided, the user cannot un-void the document. We need a controller to handle re enable the documents (and all related documents to their previous form). For example, if I approve a quote it generates a related contract and invoice. if I void the contract, the quote stays the same, which is good, and the contract and related invoice are marked void, which is also good. Now when we re enable a contract, it should also bring the related invoice back the its previous state. 
- When the date is added to the document in the top left corner, it adds the current date. The date should show the date the document was created so they reviever knows how many days the document is valid for. For example, as of now if I create a quote and email it to a client the date is shown as todays date and they have 14 days to sign it and get it back to me. now if three days later they loos it and ask me to resend it, it then has the date of when I sent the email, not the date the document was created. With that said, we also need a way for the user to update or extend the date if needed for a document. Meaning I can press a button and it would update date shown on the document to todays date when they press the button to update it. Additinally, when a document is re enabled, it should also update the date on the document to todays date. We should add another column to the datebase so we can keep record of when the actual document was created and we can have a update date column or something similar.
### Quotes
- Move "enable scope of project field on quotes" to be implemented into the customization.
### Contracts
- Move both options in the "Contract Options" section to the customization
- Remove "Advanced" Section.
### Customization
- Add Default Values for custom fields.
- For number type, add min max fields.
#### Custom Field System Implementation
How Custom fields work
- We will only allow some fields to be customized. For example we will only allow the user to edit, add, or delete fields that are like deposit and fulfillment_date which both are above the items and To/From section of the PDF. All other fields will remain fixed and cannont be deleted or added to. These two fields should auto populate in the customization tab so the user can edit them if they would like to. 

Database Changes **(EXAMPLE! Don't do these exact changes!)**
- Create contract_field_definitions table to store field definitions per org:
`CREATE TABLE contract_field_definitions (
  id INT AUTO_INCREMENT PRIMARY KEY,
  org_id INT NOT NULL,
  field_name VARCHAR(64) NOT NULL,
  field_label VARCHAR(128) NOT NULL,
  field_type ENUM('text_short','text_long','number','date') NOT NULL,
  is_required TINYINT(1) NOT NULL DEFAULT 0,
  default_value VARCHAR(255) NULL,
  min_value DECIMAL(10,2) NULL,
  max_value DECIMAL(10,2) NULL,
  applies_to JSON NOT NULL, -- e.g. ["quote","contract"]
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (org_id) REFERENCES organizations(id) ON DELETE CASCADE
);`
- Ensure contracts.custom_fields JSON column is used to store values keyed by field_name.

JSON Template Usage
- Add a section to the master settings JSON file:
`{
  "custom_fields_template": {
    "regular": [...],
    "long_term": [...],
    "on_demand": [...]
  }
}`
- Each entry should include:
    - field_name
    - field_label
    - field_type
    - is_required
    - default_value
    - min_value / max_value (if type is number)
    - applies_to: array of document types

Controller Logic
- On document creation:
    - Load applicable custom field definitions from JSON template.
    - Render form inputs dynamically based on definitions.
    - Validate user input against field rules (type, required, min/max).
    - Serialize values into custom_fields JSON column.
- On document read/edit:
    - Combine stored values with field definitions to hydrate form.
    - Prevent editing of core fields (client, tax, discount, etc.).
    - Allow editing of custom fields only within defined constraints.

UI Changes
- In Customization tab:
- Add ability to define new custom fields per document type (regular, Long term, On Demand).
- Include options for:
    - Field label
    - Field type (short/long text, number, date)
    - Required toggle
    - Default value
    - Min/max (if number)
    - Visibility checkboxes (quote, contract, invoice)
- In Document creation forms:
    - Render core fields statically.
    - Render custom fields dynamically from template or DB.
    - Group custom fields visually to reduce clutter

Note: The project already includes a `document_custom_fields` table and handlers (`src/controllers/settings/custom_fields_handler.php`) plus UI under Settings → Documents → Customization to manage custom fields (CRUD + ordering).

# Audit
- We need the data range to auto select the year that we are on. For example if we are in the year 2025, then the start and end date to set to the begining of 2025 to the end of 2025. make it date seletor, and not just year. 
- For generation options, we should by default not include invoices. 
- We need to refactor the Audit options/page so that by default we generate a CSV file using python based off of invoices with partial and full paymets (Ignore unpaid invoices), with columns of:
    - Date, Client, Doc number/ID, invoice tax, invoice tax county (if applicable), amount paid, payment method, discount, Running total.
- Add Automated scheduling with a option to type in up to 5 email addresses for auto sending emails with the audit zip folder.
- **Audit logs should not be able to ever be edited within the system to ensure data integrity**
- Option to generate a PDF as well (Default no)
- Toggle options:
    - Include invoices (paid and partially paid only).
    - Include contracts.
    - Include quotes.
- Option to quickly select presets (e.g., “Last Quarter,” “Last Month,” “All Time”).

Note: Added a Python audit generator at `tools/generate_audit.py` and `tools/requirements.txt`. It produces a CSV (and optional zip) for invoices with partial/paid payments and can email results via SMTP. Add a cron or scheduler to run the script and supply `--emails` for auto delivery.

# Receipts
- We need to create a way for the user to upload and store receipts for things they bought for the business. EX: I go to home depot to buy nails for roofing. They should be able to upload a file (image) of the receipt, and then enter in the date/amount that was on the receipt. We can add this to the "Financial" section in the header.

# W-9 forms (and other forms)
- In the "Financial" section, we need to create a "Forms & Docs" page where the user can create different form titles and then upload a form for that. For example, the user would create a new form (only input should be title of form) which is a empty storage "bucket" where the user then uploads their w-9 form. If they have multiple forms to store here, then they can create as many as they want. The display should show all forms as "cards" side by side with the title of what they created on top, and then a small preview of the file that they uploaded. To the left of the title of the card, there should be a button called "View" which then takes the user to a details page which then shows a larger preview of the file, they can download, view the pdf in its own tab of the browser, upload a new file (delete the old one when a new one is uploaded) and also email to clients OR organizatins which would email all the clients in the organization.
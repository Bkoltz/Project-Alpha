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

# Logging
- We need to make logging more involved as right now it only logs basic things.
- User actions
-   Document creation (quotes, contracts, invoices)
    - Status changes (e.g., contract → active, invoice → paid)
    - Edits to critical fields (discounts, totals, tax rates)
    - Deletions or voids
    - File uploads/downloads (signed PDFs, deliverables)
- System events
    - Scheduled jobs (recurring invoice generation, auto‑termination)
    - Sync tasks (Dropbox/TrueNAS sync success/failure)
    - API calls (Stripe payments, email gateway, external integrations)
    - Errors/exceptions (with stack trace IDs, not full dumps)
- Security
    - Failed logins
    - Permission denials
    - Role changes (user promoted/demoted)
- For storing logs, right now we generate a new log file per day, but this is not the best option. We need to switch to Rolling logs with rotation (e.g., keep one file until it hits 10MB, then rotate).
`[2025-12-10 14:22:11] [contract] [uid:3] [ip:172.16.18.1] status change | {"id":42,"old":"pending","new":"active"}`
`[2025-12-10 14:25:33] [invoice] [uid:3] [ip:172.16.18.1] payment received | {"id":17,"amount":250.00,"method":"stripe"}`
`[2025-12-10 14:30:02] [quote] [uid:2] [ip:172.16.18.1] created | {"id":55,"client":"Acme Corp"}`

# Templating
- We need to create a general filter wrapper template in twig for the document list views.

# Settings
## Terms & Conditions
- Move "Documents Valid for" to Documents -> Customization.
- Move the "Show terms on quotes" to Documents -> Quotes
- Add Terms and conditions field for On-Demand type documents
## Notifications
- Recurring invoice generation option should be under the Documents -> invoice 
- Contract Auto-termination option should be under Documnets -> contracts
## Billing
- Change bulling to say Billing & Taxes.
- Create a Tax table in the DB that holds Tax info like, Name, County (optional), rate. 
    - This way, the user can predefine a tax rate per county (If in the USA), and have better tracking.
- We still want the user to be able to just enter a percent number for tax if they don't have the county tax setup. So we need to change how tax input works when creating documents.
## Documents
### Quotes
- Move "enable scope of project field on quotes" to be implemented into the customization.
### Contracts
- Move both options in the "Contract Options" section to the customization
- Remove "Advanced" Section.
### Customization
- Add Default Values for custom fields.
- For number type, add min max fields.
#### Custom Field System Implementation
Database Changes
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
    - Load applicable custom field definitions from DB or JSON template.
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

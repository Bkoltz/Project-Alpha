# On-Demand Contracts & Quotes Feature

## Overview
This feature adds a new contract type called "On Demand" which allows for flexible invoicing without deposit requirements. Invoices are generated manually on-demand rather than automatically. The feature includes support for on-demand quotes that convert to on-demand contracts upon approval.

## Features

### 1. **On-Demand Contract Type**
- New contract type alongside Regular and Long-term contracts
- **Deposits are supported** (optional, like other contract types)
- **No billing interval fields** (Bill Every/Period not applicable)
- Manual invoice generation via button trigger
- Supports both ongoing and fixed end date contracts
- Auto-termination for contracts past their end date

### 2. **Document Prefixes**
- **ODQ-XXX**: On-Demand Quotes
- **ODC-XXX**: On-Demand Contracts
- **ODI-XXX**: On-Demand Invoices (displayed in on-demand invoices list)

### 3. **Database Changes**
The following database tables were added/modified:

#### New Table: `on_demand_contracts`
```sql
CREATE TABLE on_demand_contracts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  quote_id INT NULL,
  client_id INT NOT NULL,
  doc_number INT NULL,
  project_code VARCHAR(64) NULL,
  status ENUM('draft','pending','active','paused','cancelled','completed'),
  start_date DATE NOT NULL,
  end_date DATE NULL,
  billing_interval_count INT NOT NULL DEFAULT 1,
  billing_interval_unit ENUM('day','week','month','year'),
  discount_type ENUM('none','percent','fixed'),
  discount_value DECIMAL(10,2),
  tax_percent DECIMAL(5,2),
  subtotal DECIMAL(12,2),
  price_per_invoice DECIMAL(12,2),
  total_invoiced DECIMAL(12,2),
  invoice_count INT,
  last_invoice_date DATE NULL,
  signed_pdf_path VARCHAR(255) NULL,
  scope TEXT NULL,
  terms TEXT NULL,
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

#### Modified Table: `invoices`
Added columns:
- `long_term_contract_id` - Links to long-term contracts
- `on_demand_contract_id` - Links to on-demand contracts

#### Modified Table: `quotes`
Added columns:
- `is_on_demand` - Flag for on-demand quotes (TINYINT)
- Updated `pricing_type` ENUM to include 'on_demand'

### 4. **Controllers Created**

#### Contract Management
- `on_demand_contracts_create.php` - Creates new on-demand contracts
- `on_demand_contract_activate.php` - Activates pending contracts
- `on_demand_contract_pause.php` - Pauses active contracts
- `on_demand_contract_resume.php` - Resumes paused contracts
- `on_demand_contract_terminate.php` - Terminates contracts

#### Invoice Generation
- `on_demand_invoice_generate.php` - Manually generates invoices for active contracts

### 5. **Views Created**

#### Contract Views
- `on-demand-contracts-list.php` - Lists all on-demand contracts with:
  - Filter by client, project, and status
  - Pagination support
  - Action buttons (Activate, Generate Invoice, Pause/Resume, Terminate)
  - Invoice count and total invoiced display

#### Invoice Views
- `on-demand-invoices-list.php` - Lists invoices from on-demand contracts with:
  - Filter by client and status
  - Links back to parent contract
  - Standard invoice actions (View, Email)

#### Quote Views
- `on-demand-quotes-list.php` - Lists on-demand quotes with ODQ prefix
- Updated `quotes-create.php` - Includes on-demand option in quote creation

#### Invoice Views  
- `invoice/on-demand-invoices-list.php` - Dedicated view showing ALL on-demand invoices with ODI prefix
- `contract/on-demand-invoices-list.php` - View showing invoices for a specific on-demand contract

### 6. **Form Integration**

#### Contracts Form
Updated `contracts-create.php` to include:
- New "On Demand" radio option under "How should the client be billed?"
- Available for both ongoing and fixed end date selections
- Automatically hides deposit fields when selected
- Routes to appropriate controller based on selection

#### Quotes Form
Updated `quotes-create.php` to include:
- New "On Demand" radio option under "How should the client be billed?"
- **Automatically hides billing interval fields** (Bill Every/Period) when On Demand is selected
- **Deposits remain available** (shown when on-demand is selected)
- Hides fulfillment date field
- Creates on-demand quote that converts to on-demand contract upon approval

### 7. **Quote Approval Integration**
Updated `quote_approve.php` controller:
- When an on-demand quote is approved, it creates an on-demand contract (not a regular contract)
- On-demand contracts are created in 'pending' status
- No invoice is auto-generated (unlike regular quotes)
- Contract must be activated before invoices can be manually generated

### 8. **Auto-Termination Cron**
Created `auto_terminate_contracts.php` cron job that:
- Runs on schedule to check for expired contracts
- Auto-terminates both long-term and on-demand contracts past their end date
- Logs all terminations for audit purposes
- Updates settings with last run timestamp

## Usage

### Creating an On-Demand Quote
1. Navigate to Quotes → Create Quote
2. Check "Long-term Service Quote (Recurring Billing)"
3. Fill in start date and select contract duration (Ongoing or Fixed End Date)
4. Under "How should the client be billed?" select **On Demand**
5. **Note**: Bill Every and Period fields are automatically hidden for on-demand
6. Enter the price per invoice
7. Fill in other details (client, tax, discount, scope)
8. Submit to create the on-demand quote
9. When approved, it automatically creates an on-demand contract

### Creating an On-Demand Contract Directly
1. Navigate to Contracts → Create Contract
2. Check "Long-term Contract (Recurring Billing)"
3. Fill in start date and select contract duration (Ongoing or Fixed End Date)
4. Under "How should the client be billed?" select **On Demand**
5. Enter the price per invoice
6. Fill in other details (client, billing interval, tax, discount, scope)
7. Submit to create the contract

### Activating a Contract
1. Navigate to Contracts → On-Demand Contracts
2. Find the pending contract
3. Click "Activate" button
4. Contract is now active and ready for invoice generation

### Generating Invoices
1. Navigate to Contracts → On-Demand Contracts
2. Find an active contract
3. Click "Generate Invoice" button
4. Invoice is created with due date based on system net terms
5. Invoice appears in the on-demand invoices list

### Managing Contract Status
**Pause:** Temporarily stops invoice generation capability
**Resume:** Reactivates a paused contract
**Terminate:** Permanently marks contract as cancelled

## Database Migration

To apply these changes to your database:

```bash
# Run the updated migration file
mysql -u username -p database_name < database/migrations/000_all.sql
```

Or if you prefer to reinitialize the entire database:
```bash
# Backup existing data first!
mysqldump -u username -p database_name > backup.sql

# Drop and recreate
mysql -u username -p -e "DROP DATABASE IF EXISTS project_alpha; CREATE DATABASE project_alpha;"
mysql -u username -p project_alpha < database/migrations/000_all.sql
```

## Cron Setup

Add the auto-termination cron to your crontab:

```bash
# Run daily at 1:00 AM
0 1 * * * php /path/to/project/src/cron/auto_terminate_contracts.php

# Or run every hour
0 * * * * php /path/to/project/src/cron/auto_terminate_contracts.php
```

Make sure `cron_enabled` is set to `true` in your settings.json file.

## Navigation
- **Quotes → On-Demand Quotes**: View all on-demand quotes (ODQ prefix)
- **Contracts → On-Demand Contracts**: View all on-demand contracts (ODC prefix)
- **Invoices → On-Demand Invoices**: View ALL on-demand invoices (ODI prefix)
- **On-Demand Contracts → Invoices button**: View invoices for a specific contract
- **On-Demand Contracts List**: Contains "Generate Invoice" button for manual generation

## Key Differences from Other Types

| Feature | Regular | Long-term | On-Demand |
|---------|---------|-----------|-----------|
| Deposit | Yes | Optional | Yes (Optional) |
| Invoice Generation | Auto (on contract creation) | Auto (scheduled) | Manual |
| Billing Interval | N/A | Required | Not Applicable |
| Recurring Billing | No | Yes | Yes |
| Fixed End Date | Yes | Optional | Optional |
| Use Case | One-time projects | Subscription services | As-needed services |

## Notes

- On-demand contracts with expired end dates cannot generate new invoices
- The "Generate Invoice" button only appears for active contracts
- Each invoice generation increments the invoice count and total invoiced
- Contract auto-termination requires the cron job to be set up and enabled
- All on-demand invoices are linked to their parent contract for tracking

## Future Enhancements

Potential improvements for future versions:
- Bulk invoice generation across multiple contracts
- Invoice generation scheduling/reminders
- Contract renewal workflow
- Integration with external billing systems
- Advanced reporting for on-demand contract performance

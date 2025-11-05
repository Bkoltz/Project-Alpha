# Recurring Invoices Setup Guide

This guide explains how to set up automatic invoice generation for long-term contracts with recurring billing.

## Overview

Long-term contracts can be configured with recurring billing intervals (daily, weekly, monthly, yearly). The system automatically generates invoices based on the billing schedule.

## Cron Job Setup

### Option 1: Docker Container Cron (Recommended for Production)

Add the following to your Docker container's crontab:

```bash
# Run every day at 2:00 AM
0 2 * * * php /var/www/src/cron/generate_recurring_invoices.php >> /var/log/cron.log 2>&1
```

To set this up:

1. Exec into your web container:
   ```bash
   docker compose exec web bash
   ```

2. Install cron if not already installed:
   ```bash
   apt-get update && apt-get install -y cron
   ```

3. Add the cron job:
   ```bash
   crontab -e
   ```
   
4. Add the line above and save

5. Start cron service:
   ```bash
   service cron start
   ```

### Option 2: Host Machine Cron

If you prefer to run the cron job from your host machine:

```bash
# Run every day at 2:00 AM
0 2 * * * docker compose -f /path/to/Project-Alpha/docker-compose.yml exec -T web php /var/www/src/cron/generate_recurring_invoices.php >> /var/log/recurring-invoices.log 2>&1
```

Add this to your host's crontab with `crontab -e`.

### Option 3: Manual Execution (Testing)

For testing, you can manually run the script:

```bash
docker compose exec web php /var/www/src/cron/generate_recurring_invoices.php
```

## How It Works

### Invoice Generation Logic

1. **Active Contracts Only**: Only processes long-term contracts with status = 'active'

2. **Due Date Check**: Generates invoices when `next_invoice_date <= today`

3. **Pricing Types**:
   - **Recurring Amount**: Same amount each invoice
   - **Fixed Total**: Divides total across invoices until contract value is met

4. **Automatic Completion**: 
   - Fixed-total contracts complete when fully invoiced
   - Contracts with end dates complete when end date is passed

5. **Next Invoice Calculation**: Automatically calculates next billing date based on interval

### What Gets Created

For each due invoice:
- New invoice record in `invoices` table
- Invoice items (from contract items or generated description)
- Updates contract's `next_invoice_date`, `last_invoice_date`, and `total_invoiced`

### Contract Status Updates

- **Active → Completed**: When fixed-total contract is fully invoiced OR end date is reached
- **Paused contracts**: Skipped (no invoices generated)

## Monitoring

### Check Logs

View cron execution logs:
```bash
docker compose exec web tail -f /var/log/cron.log
```

Or check PHP error log:
```bash
docker compose logs web | grep "generate_recurring_invoices"
```

### Log Messages

- `Starting invoice generation run at [timestamp]`
- `Generated invoice INV-XXX for contract LTC-XXX ($XXX.XX)`
- `Contract LTC-XXX fully invoiced, marked as completed`
- `Completed: X invoices generated, X errors`

## Troubleshooting

### No Invoices Being Generated

1. **Check contract status**: Must be 'active'
   ```sql
   SELECT id, status, next_invoice_date FROM long_term_contracts WHERE status = 'active';
   ```

2. **Check next_invoice_date**: Must be today or earlier
   ```sql
   SELECT id, next_invoice_date FROM long_term_contracts WHERE next_invoice_date <= CURDATE();
   ```

3. **Check cron is running**:
   ```bash
   docker compose exec web service cron status
   ```

### Invoices Not Created Properly

Check error logs:
```bash
docker compose exec web cat /var/log/cron.log
```

### Testing Invoice Generation

Temporarily set a contract's `next_invoice_date` to today:
```sql
UPDATE long_term_contracts SET next_invoice_date = CURDATE() WHERE id = 1;
```

Then run the script manually to see results.

## Recommended Cron Schedule

- **Daily at 2 AM**: Recommended for most use cases
  ```
  0 2 * * * ...
  ```

- **Hourly**: For high-frequency billing (rare)
  ```
  0 * * * * ...
  ```

- **Weekly**: Less frequent check (not recommended)
  ```
  0 2 * * 0 ...
  ```

## Database Fields

### long_term_contracts table
- `next_invoice_date`: When the next invoice should be generated
- `last_invoice_date`: When the last invoice was generated
- `total_invoiced`: Cumulative amount invoiced so far
- `status`: Must be 'active' for invoicing

## Security Notes

- The cron script requires database access
- Logs may contain sensitive information - secure log files appropriately
- Consider setting up monitoring/alerts for failed invoice generation

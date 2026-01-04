# Scheduled Audit Setup Instructions

This directory contains the cron script for running scheduled audits automatically.

## Prerequisites

- PHP CLI (command-line interface) installed
- Python 3 with required dependencies (pandas, openpyxl)
- Cron access (Linux/macOS) or Task Scheduler (Windows)
- Database migrations applied (005_audit_schedules.sql)

## Setup

### 1. Database Migration

Ensure the audit schedules database migration has been applied:

```bash
# Run the migration from your database management tool or via command line
mysql -u your_user -p your_database < K:/Projects/Project-Alpha/database/migrations/005_audit_schedules.sql
```

### 2. Configure Email Settings

Edit `src/config/app.php` to ensure email settings are configured:

```php
$appConfig = [
    'brand_name' => 'Your Company Name',
    'from_email' => 'noreply@yourdomain.com',
    // ... other settings
];
```

**Note**: The current implementation uses PHP's `mail()` function. For production, consider integrating:
- PHPMailer
- SendGrid API
- AWS SES
- Other email service providers

### 3. Set Up Cron Job (Linux/macOS)

Edit your crontab:

```bash
crontab -e
```

Add one of the following entries:

**Run every hour:**
```
0 * * * * php K:/Projects/Project-Alpha/tools/run_scheduled_audits.php >> K:/Projects/Project-Alpha/logs/scheduled_audits.log 2>&1
```

**Run every 6 hours:**
```
0 */6 * * * php K:/Projects/Project-Alpha/tools/run_scheduled_audits.php >> K:/Projects/Project-Alpha/logs/scheduled_audits.log 2>&1
```

**Run daily at 3 AM:**
```
0 3 * * * php K:/Projects/Project-Alpha/tools/run_scheduled_audits.php >> K:/Projects/Project-Alpha/logs/scheduled_audits.log 2>&1
```

### 4. Set Up Task Scheduler (Windows)

1. Open Task Scheduler
2. Click "Create Basic Task"
3. Name: "Project Alpha Scheduled Audits"
4. Trigger: Choose frequency (e.g., Daily, Hourly)
5. Action: "Start a program"
   - Program/script: `php.exe`
   - Add arguments: `K:\Projects\Project-Alpha\tools\run_scheduled_audits.php`
   - Start in: `K:\Projects\Project-Alpha\tools`
6. Click "Finish"

### 5. Create Log Directory

Ensure the logs directory exists with write permissions:

```bash
mkdir -p K:/Projects/Project-Alpha/logs
chmod 755 K:/Projects/Project-Alpha/logs
```

### 6. Test the Script

Run the script manually to verify it works:

```bash
php K:/Projects/Project-Alpha/tools/run_scheduled_audits.php
```

Check the output for any errors. If no schedules are due, you should see:
```
[YYYY-MM-DD HH:MM:SS] Starting scheduled audit runner
[YYYY-MM-DD HH:MM:SS] No scheduled audits due at this time
```

## How It Works

1. **Schedule Creation**: Users create audit schedules via the Financial > Audit page
2. **Cron Execution**: The cron script runs at configured intervals
3. **Schedule Detection**: Script queries for schedules where `next_run_at <= NOW()` and `is_active = 1`
4. **Data Collection**: Fetches relevant invoices, contracts, and quotes based on schedule settings
5. **Report Generation**: Calls the Python audit generator to create CSV and optional PDFs
6. **Email Delivery**: Sends the generated ZIP file to configured recipients
7. **Schedule Update**: Updates `next_run_at` and `last_run_at` timestamps
8. **Logging**: Records success/failure in `audit_schedule_logs` table

## Schedule Frequencies

- **Weekly**: Runs every Monday
- **Monthly**: Runs on the 1st of each month
- **Quarterly**: Runs on Jan 1, Apr 1, Jul 1, Oct 1
- **Annually**: Runs on Jan 1 each year

## Date Range Types

- **Last Week**: Previous Monday to Sunday
- **Last Month**: Previous calendar month
- **Last Quarter**: Previous 3-month quarter
- **Last Year**: Previous calendar year
- **Current Year**: Jan 1 to today
- **All Time**: From 2020-01-01 to today

## Troubleshooting

### Script Not Running

1. Check cron is active: `service cron status`
2. Verify PHP path: `which php`
3. Check file permissions: `ls -la run_scheduled_audits.php`
4. Review cron logs: `/var/log/syslog` or `/var/log/cron`

### Python Errors

1. Verify Python path: `which python3`
2. Set custom Python path: `export PYTHON_PATH=/usr/bin/python3`
3. Check Python dependencies: `pip3 list | grep pandas`

### Email Not Sending

1. Check PHP mail configuration: `php -i | grep mail`
2. Review application logs
3. Consider implementing PHPMailer or another email service

### Database Connection Issues

1. Verify database credentials in `src/config/db.php`
2. Check database user has necessary permissions
3. Ensure database server is accessible from cron environment

## Monitoring

Monitor scheduled audit execution:

```sql
-- View recent schedule executions
SELECT 
    s.id,
    s.frequency,
    s.last_run_at,
    s.next_run_at,
    l.status,
    l.created_at,
    l.error_message
FROM audit_schedules s
LEFT JOIN audit_schedule_logs l ON s.id = l.schedule_id
ORDER BY l.created_at DESC
LIMIT 20;
```

Check log file:
```bash
tail -f K:/Projects/Project-Alpha/logs/scheduled_audits.log
```

## Security Considerations

- Ensure the script has minimal file system permissions
- Store email credentials securely (use environment variables)
- Limit database user permissions to only required tables
- Keep logs in a secure location with restricted access
- Consider encrypting email attachments for sensitive data

# Project Alpha (PA)

A PHP-based business document management system for quotes, contracts, and invoices with Stripe payment integration.

## Core Features

- Create and manage clients, organizations, Jobs (auto-generated project_codes), and Projects (manual parent groups)
- Draft, approve, and archive quotes
- Generate contracts and invoices automatically from quotes
- Generate downloadable PDFs for quotes, contracts, and invoices
- Upload signed contracts (signed PDF files) and serve them securely
- Long-term and on-demand document support (project-level configurations)
- Stripe payment integration (Payment Intents, not Stripe Invoices)
- Public shareable links for quotes, contracts, and invoices
- Automated invoice generation and reminders

## Quick Start

### Docker Setup

```bash
docker compose build --no-cache
docker compose up -d
```

The application will be available at `http://localhost/` (the container listens on port 80 by default).

### Run Migrations

After starting Docker, run the migrations:

```bash
docker compose exec app php /var/www/src/migrations/add_cron_job_runs.php
docker compose exec app php /var/www/src/migrations/add_amount_paid.php
```

---

## Stripe Integration

PA uses **Stripe Payment Intents** (not Stripe Invoices) for card payments. This avoids Stripe invoice fees while maintaining PCI compliance.

### Configuration

1. Go to **Settings → Billing** in the PA admin
2. Enter your Stripe keys:
   - **Publishable Key**: `pk_live_...` or `pk_test_...`
   - **Secret Key**: `sk_live_...` or `sk_test_...`
   - **Webhook Secret**: `whsec_...`

### Webhook Setup

Configure your Stripe webhook to send events to:

```
https://your-domain.com/?page=stripe-webhook
```

**Required events:**
- `checkout.session.completed` - Handles Stripe Checkout payments
- `payment_intent.succeeded` - Handles direct Payment Intent payments (auto-pay)

**Optional events:**
- `payment_intent.payment_failed` - Log failed payments
- `charge.refunded` - Handle refunds
- `charge.dispute.created` - Handle disputes

### Metadata Convention

All Payment Intents include metadata for reconciliation:

```json
{
  "pa_invoice_id": "12345",
  "invoice_id": "12345",
  "doc_number": "1001"
}
```

The `pa_invoice_id` is the primary identifier used for linking Stripe payments to PA invoices.

### Payment Flow

1. **Public Invoice Payment**: Client clicks "Pay with Card" on public invoice link
2. **Admin Card Charge**: Admin clicks "Charge Card" from invoice details
3. **Record Payment (Stripe)**: Admin selects Stripe from payment methods dropdown

All methods redirect to Stripe Checkout, and the webhook updates the invoice status automatically.

---

## Cron Jobs

PA uses scheduled cron jobs for automated tasks. All jobs track their execution in the `cron_job_runs` table for catch-up after downtime.

### Available Jobs

| Job | Description | Recommended Schedule |
|-----|-------------|---------------------|
| `generate_recurring_invoices.php` | Creates invoices for long-term contracts | Daily at midnight |
| `send_invoice_reminders.php` | Sends due/overdue reminders | Daily |
| `stripe_reconciliation.php` | Syncs missed Stripe payments | Every 6 hours |
| `auto_terminate_contracts.php` | Ends expired contracts | Daily |
| `link_expiration_checker.php` | Expires old public links | Daily |

### Cron Configuration (Docker)

Add to your container's crontab:

```cron
# Generate recurring invoices (daily at midnight)
0 0 * * * php /var/www/src/cron/generate_recurring_invoices.php >> /var/log/cron.log 2>&1

# Send invoice reminders (daily at 9am)
0 9 * * * php /var/www/src/cron/send_invoice_reminders.php >> /var/log/cron.log 2>&1

# Stripe reconciliation (every 6 hours)
0 */6 * * * php /var/www/src/cron/stripe_reconciliation.php >> /var/log/cron.log 2>&1

# Auto-terminate contracts (daily at 1am)
0 1 * * * php /var/www/src/cron/auto_terminate_contracts.php >> /var/log/cron.log 2>&1

# Link expiration check (daily at 2am)
0 2 * * * php /var/www/src/cron/link_expiration_checker.php >> /var/log/cron.log 2>&1
```

### Catch-Up & Resilience

If PA goes offline, cron jobs automatically catch up on missed work:

1. **Invoice Generation**: Uses `next_invoice_date` on contracts to generate all missed invoices
2. **Stripe Reconciliation**: Fetches Payment Intents from Stripe API since last run, records any missed payments
3. **Reminders**: Uses `invoice_notifications` table to avoid duplicate sends

The `cron_job_runs` table tracks:
- `job_name`: Unique identifier
- `last_run`: Timestamp of last successful run
- `status`: success/failed
- `error_message`: Details if failed

---

## API Endpoints

### Public Endpoints (No Auth Required)

| Route | Description |
|-------|-------------|
| `/?page=public-doc&token=...` | View public quote/contract/invoice |
| `/?page=stripe-checkout&token=...` | Initiate Stripe payment |
| `/?page=stripe-success&token=...` | Payment success page |
| `/?page=stripe-webhook` | Stripe webhook receiver |

### Admin Endpoints (Auth Required)

| Route | Description |
|-------|-------------|
| `/?page=stripe-charge&invoice_id=...` | Admin-initiated card charge |
| `/?page=public-link-create` | Generate shareable link |
| `/?page=payments/payments-create` | Record payment (supports Stripe redirect) |

---

## Database Schema

### Key Tables

- **`invoices`**: Invoice records with status tracking
- **`payments`**: Payment records with `stripe_payment_intent_id` for reconciliation
- **`contracts`**: Standard contracts with deposit tracking
- **`long_term_contracts`**: Recurring billing contracts
- **`on_demand_contracts`**: On-demand service contracts
- **`public_links`**: Shareable document links with expiration
- **`cron_job_runs`**: Cron execution tracking for catch-up logic
- **`invoice_notifications`**: Tracks sent reminders (idempotency)

### Payments Table Columns

```sql
id, invoice_id, amount, method, check_number, notes,
stripe_payment_intent_id, stripe_subscription_id, stripe_charge_id,
auto_pay_attempt, payment_method_id, status, created_at
```

---

## Environment Variables

Configure in `config/settings.json` or via Settings UI:

| Setting | Description |
|---------|-------------|
| `stripe_publishable_key` | Stripe publishable key |
| `stripe_secret_key_enc` | Encrypted Stripe secret key |
| `stripe_webhook_secret_enc` | Encrypted webhook signing secret |
| `cron_enabled` | Enable/disable cron jobs |
| `smtp_host`, `smtp_port`, etc. | Email configuration |
| `net_terms_days` | Default payment terms (days) |
| `documents_valid_days` | Public link expiration days |

---

## Troubleshooting

### Stripe Payments Not Recording

1. Check webhook is configured in Stripe Dashboard
2. Verify webhook secret matches in PA settings
3. Check `/?page=stripe-webhook` is accessible (no auth)
4. Review error logs: `docker compose logs app`

### Missed Payments After Downtime

Run manual reconciliation:

```bash
docker compose exec app php /var/www/src/cron/stripe_reconciliation.php
```

### Cron Jobs Not Running

1. Verify `cron_enabled` is set to `true` in settings
2. Check cron service is running in container
3. Review `cron_job_runs` table for errors:

```sql
SELECT * FROM cron_job_runs ORDER BY updated_at DESC;
```

---

## File Structure

```
src/
├── config/           # Database and app configuration
├── controllers/      # Request handlers
│   ├── contract/     # Contract CRUD
│   ├── invoice/      # Invoice CRUD
│   ├── public_view/  # Public document views
│   └── stripe_*.php  # Stripe payment handlers
├── cron/             # Scheduled job scripts
├── migrations/       # Database migrations
├── services/         # Business logic (StripeService, etc.)
├── utils/            # Helpers (crypto, mailer, etc.)
└── views/            # HTML templates
docs/
└── work_flow/        # Detailed workflow documentation
    ├── document_types.md
    ├── projects.md
    ├── regular_docs.md
    ├── long-term_docs.md
    └── settings.md
```

---

## Contributing and Development

If you'd like to contribute or extend the project, please read through the `docs/work_flow` docs first. The public-facing routes are routed through `public/index.php` where `page` query parameters map to controllers and views under `src/controllers` and `src/views/pages`.

To run tests:

```bash
composer install
vendor/bin/phpunit --colors=always
```

---

## License

Proprietary - All rights reserved.
test
test

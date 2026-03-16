# Stripe Payment Flow (Without Using Stripe Invoices)
## Overview
Project Alpha (PA) processes card payments using Stripe Payment Intents, not Stripe Invoices. This avoids Stripe’s invoice fees while still allowing secure, PCI‑compliant card processing. PA creates a Payment Intent for each invoice and attaches internal identifiers using Stripe metadata. When the payment succeeds, Stripe notifies PA through a webhook, allowing PA to update the invoice status automatically.

### Payment Intent Creation
When a user opens an invoice and chooses to pay:
- PA creates a Payment Intent with:
- amount
- currency
- customer (optional)
- metadata containing PA’s internal invoice ID
Example metadata:
{
  "pa_invoice_id": "12345",
  "project_id": "88",
  "client_id": "42"
}


This metadata is returned to PA in the webhook event.

### Required Webhook
PA listens for:
```
payment_intent.succeeded
```
This event fires when a card payment is fully completed.
PA uses this webhook to:
- read the metadata
- find the matching internal invoice
- mark the invoice as paid
- record payment details
- update project totals
- trigger any automations (e.g., send receipt)
Optional additional webhooks:
- payment_intent.payment_failed
- charge.refunded
- charge.dispute.created

Webhook Processing Flow
- Stripe sends payment_intent.succeeded.
- PA validates the event signature.
- PA extracts metadata:
event.data.object.metadata.pa_invoice_id
- PA updates the invoice:
- status → paid
- amount paid
- payment date
- payment method
- PA logs the event in the audit log.

### Benefits of This Method
- No Stripe invoice fees
- Full control over invoice layout and numbering
- Real‑time payment updates
- Secure and PCI‑compliant
- Perfect for custom billing systems like PA

# Storing Cards for Monthly Auto‑Billing
Stripe can store card information for you, and you never touch or store card numbers yourself. This keeps you out of PCI scope and allows automatic monthly billing.
There are two ways to do this:

### Option 1 — Stripe Customer + Saved Payment Method (Recommended)
You create a Stripe Customer for each client.
Stripe stores their card securely.
How it works:
- Client enters card info using Stripe Elements or Checkout.
- Stripe attaches the card to the Customer.
- PA stores only:
- stripe_customer_id
- stripe_payment_method_id (optional)
- Each month, PA creates a Payment Intent and charges the saved card automatically.
Benefits:
- You never store card data
- Fully PCI‑compliant
- Works with your existing custom invoice system
- No Stripe invoice fees
- Full control over billing cycles


### Recommended Setup for PA Monthly Hosting Billing
1. Create a Stripe Customer for each hosting client
Store only the stripe_customer_id in your database.
2. Collect card info using Stripe Elements or Checkout
Stripe stores the card.
You receive a payment_method_id.
3. Attach the payment method to the customer
This becomes the default card.
4. Each month:
- PA creates a Payment Intent
- Charges the saved card
- Uses metadata to link the charge to your internal invoice
- Listens for payment_intent.succeeded
- Marks the invoice as paid automatically
5. Optional:
Enable Stripe’s Customer Portal so clients can update their card themselves.


# Project Alpha – Catch‑Up & Resilience Architecture
A complete reference for handling downtime, missed cron jobs, and missed Stripe webhooks
This document explains how Project Alpha (PA) should recover from downtime, power loss, container restarts, or missed Stripe webhooks. The goal is to ensure PA behaves as if it never went offline — all invoices get generated, all emails get sent, and all payments get recorded.

1. Why Catch‑Up Logic Is Required
PA relies heavily on automation:
- nightly cron jobs
- invoice generation
- long‑term contract billing
- on‑demand contract billing
- overdue reminders
- Stripe payment webhooks
- auto‑pay attempts
If the server or Docker container goes down, these automations pause. Without a catch‑up system, PA would:
- miss invoices
- skip reminders
- fail to auto‑bill
- lose Stripe payment notifications
- show incorrect project totals
The catch‑up architecture ensures PA can always reconstruct what should have happened.

2. Cron Job Catch‑Up System
2.1. Add a cron_job_runs Table
This table tracks the last successful run of each automation job.
CREATE TABLE IF NOT EXISTS cron_job_runs (
  id INT AUTO_INCREMENT PRIMARY KEY,
  job_name VARCHAR(100) NOT NULL UNIQUE,
  last_run DATETIME NULL,
  status ENUM('success','failed') NOT NULL DEFAULT 'success',
  error_message TEXT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);


Purpose
- job_name uniquely identifies each job
- last_run tells PA when the job last completed
- status helps debugging
- error_message stores failure details
This table is the backbone of your catch‑up system.

2.2. How Each Cron Job Should Behave
Every cron job (invoice generation, reminders, auto‑pay, etc.) should follow this pattern:
Step 1 — Read last_run
Example:
- Job should run nightly
- last_run = 3 days ago
- PA knows it missed 3 cycles
Step 2 — Compute the missing window
If the job runs daily:
- Loop through each missing day
- Or compute a date range
Step 3 — Run the job logic for each missing period
Examples:
- Invoice generation:
- Use long_term_contracts.next_invoice_date
- Use on_demand_contracts.last_invoice_date
- Generate invoices for all missed periods
- Email reminders:
- Check invoice_notifications
- Only send if not already sent
Step 4 — Idempotency checks
Before creating anything:
- Has an invoice already been created for this period?
- Has this reminder already been sent?
- Has this contract already been billed?
If yes → skip
If no → create/send
Step 5 — Update cron_job_runs
After success:
UPDATE cron_job_runs
SET last_run = NOW(), status = 'success'
WHERE job_name = 'generate_invoices';



2.3. Container Restart Behavior
When the Docker container starts:
Option A — Run a bootstrap catch‑up script
On startup, PA:
- Reads cron_job_runs
- Detects missed periods
- Runs catch‑up logic immediately
Option B — Let cron handle it
Your normal cron jobs:
- See that last_run is old
- Process all missed periods
Either way, downtime becomes irrelevant.

3. Stripe Payment Catch‑Up System
Stripe payments must never be lost, even if:
- PA is offline
- webhooks fail
- network issues occur
- Stripe retries expire
Your schema already supports this with:
- payments.stripe_payment_intent_id
- payments.status
- payments.invoice_id
- payments.stripe_subscription_id
- payments.auto_pay_attempt
This is exactly what we need.

3.1. Normal Flow (When PA Is Online)
- PA creates a Payment Intent
- PA stores stripe_payment_intent_id in payments
- Stripe processes the card
- Stripe sends payment_intent.succeeded
- PA updates:
- payments.status = 'succeeded'
- invoices.status = 'paid' or partial
- project totals
- audit logs
This is the ideal path.

3.2. What Happens If PA Is Offline?
Stripe retries webhooks for 72 hours.
If PA is offline longer than that, Stripe stops retrying.
Without a catch‑up system, PA would never know the payment succeeded.

3.3. Stripe Reconciliation Job (Critical)
Add a cron job named stripe_reconciliation.
What it does:
- Reads cron_job_runs.last_run
- Fetches Payment Intents from Stripe created/updated since that time
- For each Payment Intent:
- If status = succeeded:
- Match using metadata (pa_invoice_id)
- Or match using stripe_payment_intent_id
- Update your database:
- Mark payment as succeeded
- Update invoice status
- Update project totals
- Update cron_job_runs
This guarantees:
- No payment is ever lost
- No invoice stays unpaid incorrectly
- No project total is wrong
Even if PA was offline for a week.

4. Auto‑Pay Catch‑Up (Long‑Term & On‑Demand Contracts)
Your schema already supports:
- auto_pay_enabled
- payment_method_id
- stripe_subscription_id
- next_invoice_date
- last_invoice_date
- total_invoiced
Auto‑pay catch‑up works like this:
- Cron job generates missed invoices
- For each invoice belonging to an auto‑pay contract:
- Create a Payment Intent using the saved Stripe customer/payment method
- Insert a payments row with auto_pay_attempt = 1
- Stripe processes the payment
- If PA is offline:
- Stripe retries
- Reconciliation job catches anything missed
This makes auto‑pay fully resilient.

5. Full System Behavior During Downtime
If PA goes down:
- Cron jobs stop
- Webhooks may fail
- Auto‑pay pauses
- Invoice generation pauses
When PA comes back up:
- Cron catch‑up fills in:
- missed invoices
- missed reminders
- missed auto‑pay attempts
- missed contract billing cycles
- Stripe reconciliation fills in:
- missed payments
- missed auto‑pay successes
- missed subscription renewals
- System state becomes correct
- All invoices exist
- All payments are recorded
- All reminders are logged
- All project totals are accurate
PA becomes self‑healing.

6. Implementation Checklist
Database
- [ ] Add cron_job_runs table
- [ ] Ensure payments.stripe_payment_intent_id is always stored
- [ ] Ensure invoices and payments support partial payments
Cron Jobs
- [ ] Wrap each job with catch‑up logic
- [ ] Add stripe_reconciliation job
- [ ] Add idempotency checks
Stripe
- [ ] Always include metadata.pa_invoice_id
- [ ] Implement payment_intent.succeeded webhook
- [ ] Implement reconciliation script
Container Startup
- [ ] Optional: run bootstrap catch‑up script
- [ ] Or rely on cron to detect missed periods

7. Summary
Project Alpha becomes fully resilient by combining:
- Cron catch‑up
- Idempotent job logic
- Stripe webhook retries
- Stripe reconciliation
- Startup recovery
This ensures:
- No invoice is ever missed
- No payment is ever lost
- No reminder is ever skipped
- No project total is ever wrong
Even if the server crashes, the container restarts, or PA is offline for days

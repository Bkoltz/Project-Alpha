# Testing Instructions for Project Alpha

## Testing the Invoice Details Page

1. Log in to the app at https://pa.ledgetopdroneservices.com
2. Go to Invoices → click "View" on any invoice
3. The invoice details page should load without errors
4. The "Mark as Paid" button should redirect to the payment form with:
   - Invoice ID pre-filled
   - Amount pre-filled with the outstanding balance

## Testing Stripe Webhooks

1. Configure Stripe test keys in Settings → Billing:
   - Test Secret Key
   - Test Publishable Key
   - Webhook Signing Secret

2. Create a test invoice
3. Click "Charge Card" on the invoice
4. Complete the Stripe checkout in test mode
5. Check that:
   - The webhook is received (check logs)
   - The invoice status updates to "paid"
   - A payment record is created

## Testing the "Mark as Paid" Button

1. Go to Invoices → click "View" on an unpaid invoice
2. Click "Mark as Paid"
3. You should be redirected to the payment form
4. The invoice ID and amount should be pre-filled
5. Enter payment details and save
6. The invoice status should update to "paid"

## What Was Fixed

### Invoice Details Page
- Added `$outstanding` variable calculation that was missing
- The page was referencing `$outstanding` before it was defined

### "Mark as Paid" Button
- Changed from POST form to GET link
- Now redirects to payment form with `invoice_id` and `amount` pre-filled

### Database
- Added missing tables that were in `000_all.sql` but not in numbered migrations:
  - `item_library`
  - `document_custom_fields`
  - `audit_schedules`
  - `audit_schedule_logs`
  - `form_categories`
  - `form_documents`
  - `receipt_stores`
  - `public_links`

### Settings Save
- Fixed `/var/www/config/` ownership to `www-data:www-data` for Docker

### Stripe Webhooks
- Added invoice existence check before recording payment
- Returns 200 OK for non-existent invoices to prevent Stripe retries
- Added documentation in `docs/stripe-webhook-setup.md`

-- Migration 029: Add unique index on invoice_notifications to prevent duplicate emails
-- Council review #13 critical fix: email idempotency must be enforced at DB level

-- Add unique constraint on (invoice_id, notification_type) so the same notification
-- can never be inserted twice (prevents duplicate emails from race conditions
-- between the on-demand controller and the cron).
-- Using INSERT IGNORE in code will silently skip duplicates.

-- First, clean up any existing duplicates (keep the earliest by id)
DELETE n1 FROM invoice_notifications n1
INNER JOIN invoice_notifications n2
WHERE n1.id > n2.id
  AND n1.invoice_id = n2.invoice_id
  AND n1.notification_type = n2.notification_type;

-- Add the unique index
ALTER TABLE invoice_notifications
ADD UNIQUE INDEX uq_invoice_notification (invoice_id, notification_type);
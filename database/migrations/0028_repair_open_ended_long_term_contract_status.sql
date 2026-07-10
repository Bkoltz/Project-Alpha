-- Restore open-ended recurring contracts that were incorrectly completed when
-- one of their invoices was paid. Legitimate termination records have an end
-- date; fixed-total contracts may also complete after their final installment
-- is generated, so both are intentionally excluded.
UPDATE contracts
SET status = 'active'
WHERE contract_type = 'long_term'
  AND pricing_type = 'per_invoice'
  AND status = 'completed'
  AND completed_at IS NULL
  AND end_date IS NULL
  AND next_invoice_date IS NOT NULL;

-- Older recurring invoices were created as unpaid without initializing their
-- stored balance. Recalculate only collectible recurring invoices; paid and
-- terminal invoice history remains untouched.
UPDATE invoices
SET balance_due = GREATEST(total - amount_paid, 0)
WHERE invoice_type = 'long_term'
  AND status IN ('sent', 'unpaid', 'partial', 'overdue');

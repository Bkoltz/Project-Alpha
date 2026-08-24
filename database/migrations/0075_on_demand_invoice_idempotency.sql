-- Migration 0075: request-scoped idempotency for interactive on-demand invoice generation.
SET @pa_has_od_generation_key := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='generation_key'
);
SET @pa_sql := IF(@pa_has_od_generation_key=0,
  'ALTER TABLE invoices ADD COLUMN generation_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL AFTER generated_at',
  'SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

SET @pa_has_od_generation_key_index := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='uq_invoice_generation_key'
);
SET @pa_sql := IF(@pa_has_od_generation_key_index=0,
  'ALTER TABLE invoices ADD UNIQUE KEY uq_invoice_generation_key (generation_key)',
  'SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

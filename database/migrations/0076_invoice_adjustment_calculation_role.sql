-- Migration 0076: distinguish informational adjustment history from rows that participate in
-- authoritative invoice totals. Existing rows remain informational so this
-- migration never changes a historical balance.
SET @pa_has_adjustment_affects_total := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_adjustments'
    AND COLUMN_NAME='affects_total'
);
SET @pa_sql := IF(@pa_has_adjustment_affects_total=0,
  'ALTER TABLE invoice_adjustments ADD COLUMN affects_total TINYINT(1) NOT NULL DEFAULT 0 AFTER amount',
  'SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

SET @pa_has_adjustment_total_index := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_adjustments'
    AND INDEX_NAME='idx_invoice_adjustment_total_role'
);
SET @pa_sql := IF(@pa_has_adjustment_total_index=0,
  'ALTER TABLE invoice_adjustments ADD KEY idx_invoice_adjustment_total_role (invoice_id,affects_total,superseded_at,id)',
  'SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

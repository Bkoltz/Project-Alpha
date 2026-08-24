-- Migration 0074: immutable lineage for pricing copied from an accepted document snapshot.
SET @pa_has_pricing_lineage := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_pricing_adjustment_snapshots'
    AND COLUMN_NAME='derived_from_snapshot_id'
);
SET @pa_sql := IF(@pa_has_pricing_lineage=0,
  'ALTER TABLE document_pricing_adjustment_snapshots ADD COLUMN derived_from_snapshot_id BIGINT UNSIGNED NULL AFTER applied_by',
  'SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

SET @pa_has_pricing_lineage_index := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='document_pricing_adjustment_snapshots'
    AND INDEX_NAME='idx_pricing_snapshot_lineage'
);
SET @pa_sql := IF(@pa_has_pricing_lineage_index=0,
  'ALTER TABLE document_pricing_adjustment_snapshots ADD KEY idx_pricing_snapshot_lineage (derived_from_snapshot_id)',
  'SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

SET @pa_has_pricing_lineage_fk := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA=DATABASE() AND TABLE_NAME='document_pricing_adjustment_snapshots'
    AND CONSTRAINT_NAME='fk_pricing_snapshot_lineage'
);
SET @pa_sql := IF(@pa_has_pricing_lineage_fk=0,
  'ALTER TABLE document_pricing_adjustment_snapshots ADD CONSTRAINT fk_pricing_snapshot_lineage FOREIGN KEY (derived_from_snapshot_id) REFERENCES document_pricing_adjustment_snapshots(id) ON DELETE RESTRICT',
  'SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

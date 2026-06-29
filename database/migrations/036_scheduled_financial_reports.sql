-- Migration 036: Support both audit and expense email report schedules.
USE project_alpha;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='audit_schedules' AND column_name='report_type'
);
SET @sql := IF(
    @has_col=0,
    'ALTER TABLE audit_schedules ADD COLUMN report_type ENUM("audit","expense") NOT NULL DEFAULT "audit" AFTER organization_id',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_col := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='audit_schedules' AND column_name='filters'
);
SET @sql := IF(
    @has_col=0,
    'ALTER TABLE audit_schedules ADD COLUMN filters JSON NULL AFTER options',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_index := (
    SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema=DATABASE() AND table_name='audit_schedules' AND index_name='idx_audit_sched_type'
);
SET @sql := IF(
    @has_index=0,
    'ALTER TABLE audit_schedules ADD INDEX idx_audit_sched_type (organization_id, report_type, is_active)',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

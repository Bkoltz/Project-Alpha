-- Migration 0073: installation-wide and optional customer-scoped pricing definitions.
-- Existing 0072 rows remain customer scoped. This migration is retry-safe and
-- does not create assignments or alter historical document prices.
SET @pa_has_pricing_org_fk := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='pricing_adjustment_definitions' AND constraint_name='fk_pricing_adjustment_org' AND constraint_type='FOREIGN KEY');
SET @pa_sql := IF(@pa_has_pricing_org_fk>0,'ALTER TABLE pricing_adjustment_definitions DROP FOREIGN KEY fk_pricing_adjustment_org','SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

SET @pa_has_old_scope_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='pricing_adjustment_definitions' AND index_name='uq_pricing_adjustment_name');
SET @pa_sql := IF(@pa_has_old_scope_index>0,'ALTER TABLE pricing_adjustment_definitions DROP INDEX uq_pricing_adjustment_name','SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

ALTER TABLE pricing_adjustment_definitions MODIFY organization_id INT NULL;

SET @pa_has_scope_type := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pricing_adjustment_definitions' AND column_name='scope_type');
SET @pa_sql := IF(@pa_has_scope_type=0,"ALTER TABLE pricing_adjustment_definitions ADD COLUMN scope_type ENUM('installation','customer') NOT NULL DEFAULT 'customer' AFTER organization_id",'SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

SET @pa_has_scope_key := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='pricing_adjustment_definitions' AND column_name='scope_key');
SET @pa_sql := IF(@pa_has_scope_key=0,"ALTER TABLE pricing_adjustment_definitions ADD COLUMN scope_key VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '' AFTER scope_type",'SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

UPDATE pricing_adjustment_definitions
SET scope_type=IF(organization_id IS NULL,'installation','customer'),
    scope_key=IF(organization_id IS NULL,'installation',CONCAT('customer:',organization_id))
WHERE scope_key='';

SET @pa_has_scope_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='pricing_adjustment_definitions' AND index_name='uq_pricing_adjustment_scope_name');
SET @pa_sql := IF(@pa_has_scope_index=0,'ALTER TABLE pricing_adjustment_definitions ADD UNIQUE KEY uq_pricing_adjustment_scope_name (scope_key,name)','SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

SET @pa_has_customer_scope_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='pricing_adjustment_definitions' AND index_name='idx_pricing_adjustment_customer_scope');
SET @pa_sql := IF(@pa_has_customer_scope_index=0,'ALTER TABLE pricing_adjustment_definitions ADD KEY idx_pricing_adjustment_customer_scope (organization_id,scope_type)','SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

SET @pa_has_pricing_org_fk := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='pricing_adjustment_definitions' AND constraint_name='fk_pricing_adjustment_org' AND constraint_type='FOREIGN KEY');
SET @pa_sql := IF(@pa_has_pricing_org_fk=0,'ALTER TABLE pricing_adjustment_definitions ADD CONSTRAINT fk_pricing_adjustment_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE','SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

SET @pa_has_scope_check := (SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema=DATABASE() AND table_name='pricing_adjustment_definitions' AND constraint_name='chk_pricing_adjustment_scope' AND constraint_type='CHECK');
SET @pa_sql := IF(@pa_has_scope_check=0,"ALTER TABLE pricing_adjustment_definitions ADD CONSTRAINT chk_pricing_adjustment_scope CHECK ((scope_type='installation' AND organization_id IS NULL AND scope_key='installation') OR (scope_type='customer' AND organization_id IS NOT NULL AND scope_key=CONCAT('customer:',organization_id)))",'SELECT 1');
PREPARE pa_stmt FROM @pa_sql; EXECUTE pa_stmt; DEALLOCATE PREPARE pa_stmt;

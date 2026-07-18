-- Unified client services, reusable work activities, and ad-hoc service jobs.
-- Existing Item Library and Work Type identifiers remain stable for compatibility.

SET @has_component_client_treatment := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='catalog_work_components' AND column_name='client_billing_treatment');
SET @sql := IF(@has_component_client_treatment=0, 'ALTER TABLE catalog_work_components ADD COLUMN client_billing_treatment ENUM(''hourly'',''fixed_price_included'',''base_overage'',''internal'') NOT NULL DEFAULT ''fixed_price_included'' AFTER assignment_required', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_component_client_rate := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='catalog_work_components' AND column_name='client_billing_rate');
SET @sql := IF(@has_component_client_rate=0, 'ALTER TABLE catalog_work_components ADD COLUMN client_billing_rate DECIMAL(12,4) NULL AFTER client_billing_treatment', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_component_client_minutes := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='catalog_work_components' AND column_name='client_included_minutes');
SET @sql := IF(@has_component_client_minutes=0, 'ALTER TABLE catalog_work_components ADD COLUMN client_included_minutes INT UNSIGNED NULL AFTER client_billing_rate', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_component_client_overage := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='catalog_work_components' AND column_name='client_overage_rate');
SET @sql := IF(@has_component_client_overage=0, 'ALTER TABLE catalog_work_components ADD COLUMN client_overage_rate DECIMAL(12,4) NULL AFTER client_included_minutes', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_component_client_currency := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='catalog_work_components' AND column_name='client_billing_currency');
SET @sql := IF(@has_component_client_currency=0, 'ALTER TABLE catalog_work_components ADD COLUMN client_billing_currency CHAR(3) NOT NULL DEFAULT ''USD'' AFTER client_overage_rate', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Preserve the existing meaning of catalog links. Hourly services remain hourly;
-- fixed-price services, fees, and packages include linked activity time by default.
UPDATE catalog_work_components c
JOIN item_library i ON i.id=c.item_library_id
SET c.client_billing_treatment=CASE WHEN i.billing_unit='hour' THEN 'hourly' ELSE 'fixed_price_included' END,
    c.client_billing_rate=CASE WHEN i.billing_unit='hour' THEN i.unit_price ELSE c.client_billing_rate END
WHERE @has_component_client_treatment=0;

SET @has_job_origin := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='jobs' AND column_name='job_origin');
SET @sql := IF(@has_job_origin=0, 'ALTER TABLE jobs ADD COLUMN job_origin ENUM(''planned'',''unscheduled_time'') NOT NULL DEFAULT ''planned'' AFTER job_code, ADD INDEX idx_job_origin_status (job_origin,status,updated_at)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_job_completed_at := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='jobs' AND column_name='completed_at');
SET @sql := IF(@has_job_completed_at=0, 'ALTER TABLE jobs ADD COLUMN completed_at DATETIME(6) NULL AFTER status', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Client-less jobs support walk-in/cash service work. Retain the relationship
-- whenever a client exists, but never erase job history when a client is removed.
ALTER TABLE jobs DROP FOREIGN KEY fk_job_client;
ALTER TABLE jobs MODIFY COLUMN client_id INT NULL;
ALTER TABLE jobs ADD CONSTRAINT fk_job_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;

ALTER TABLE job_work_components MODIFY COLUMN source_type ENUM('quote','contract','invoice','catalog','manual','time_entry') NOT NULL;

SET @has_job_work_client_treatment := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='job_work_components' AND column_name='client_billing_treatment_snapshot');
SET @sql := IF(@has_job_work_client_treatment=0, 'ALTER TABLE job_work_components ADD COLUMN client_billing_treatment_snapshot ENUM(''hourly'',''fixed_price_included'',''base_overage'',''internal'') NULL AFTER compensation_snapshot', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_job_work_client_rate := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='job_work_components' AND column_name='client_billing_rate_snapshot');
SET @sql := IF(@has_job_work_client_rate=0, 'ALTER TABLE job_work_components ADD COLUMN client_billing_rate_snapshot DECIMAL(12,4) NULL AFTER client_billing_treatment_snapshot', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_job_work_client_minutes := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='job_work_components' AND column_name='client_included_minutes_snapshot');
SET @sql := IF(@has_job_work_client_minutes=0, 'ALTER TABLE job_work_components ADD COLUMN client_included_minutes_snapshot INT UNSIGNED NULL AFTER client_billing_rate_snapshot', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_job_work_client_overage := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='job_work_components' AND column_name='client_overage_rate_snapshot');
SET @sql := IF(@has_job_work_client_overage=0, 'ALTER TABLE job_work_components ADD COLUMN client_overage_rate_snapshot DECIMAL(12,4) NULL AFTER client_included_minutes_snapshot', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_job_work_client_currency := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='job_work_components' AND column_name='client_billing_currency_snapshot');
SET @sql := IF(@has_job_work_client_currency=0, 'ALTER TABLE job_work_components ADD COLUMN client_billing_currency_snapshot CHAR(3) NULL AFTER client_overage_rate_snapshot', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Existing planned work receives a one-time immutable snapshot from its linked
-- service/activity configuration. Runtime materialization snapshots new work.
UPDATE job_work_components jwc
JOIN catalog_work_components c ON c.id=jwc.catalog_work_component_id
JOIN item_library i ON i.id=c.item_library_id
SET jwc.client_billing_treatment_snapshot=c.client_billing_treatment,
    jwc.client_billing_rate_snapshot=CASE
        WHEN c.client_billing_treatment IN ('fixed_price_included','base_overage') THEN i.unit_price
        ELSE c.client_billing_rate
    END,
    jwc.client_included_minutes_snapshot=c.client_included_minutes,
    jwc.client_overage_rate_snapshot=c.client_overage_rate,
    jwc.client_billing_currency_snapshot=c.client_billing_currency
WHERE jwc.client_billing_treatment_snapshot IS NULL;

ALTER TABLE payments DROP FOREIGN KEY fk_payments_client;
ALTER TABLE payments MODIFY COLUMN client_id INT NULL;
ALTER TABLE payments ADD CONSTRAINT fk_payments_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE SET NULL;

SET @has_payment_job := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='payments' AND column_name='job_id');
SET @sql := IF(@has_payment_job=0, 'ALTER TABLE payments ADD COLUMN job_id INT NULL AFTER invoice_id, ADD INDEX idx_payments_job (job_id), ADD CONSTRAINT fk_payments_job FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Make Service pricing authoritative and constrain current Service/Work Activity
-- links to an optional one-to-one relationship. Historical snapshots and
-- inactive legacy components remain readable.

SET @has_service_pricing_model := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='client_pricing_model'
);
SET @sql := IF(@has_service_pricing_model=0,
    'ALTER TABLE item_library
       ADD COLUMN client_pricing_model ENUM(''fixed'',''hourly'',''base_overage'') NOT NULL DEFAULT ''fixed'' AFTER unit_price,
       ADD COLUMN client_included_minutes INT UNSIGNED NULL AFTER client_pricing_model,
       ADD COLUMN client_overage_rate DECIMAL(12,4) NULL AFTER client_included_minutes,
       ADD COLUMN pricing_currency CHAR(3) NOT NULL DEFAULT ''USD'' AFTER client_overage_rate',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

UPDATE item_library
SET client_pricing_model=CASE WHEN billing_unit='hour' THEN 'hourly' ELSE 'fixed' END,
    pricing_currency=COALESCE(NULLIF(pricing_currency,''),'USD')
WHERE @has_service_pricing_model=0;

CREATE TABLE IF NOT EXISTS catalog_link_migration_review (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    catalog_work_component_id INT NOT NULL,
    item_library_id INT NOT NULL,
    work_type_id INT NOT NULL,
    conflict_type ENUM('service_multiple_activities','activity_multiple_services') NOT NULL,
    review_status ENUM('pending','resolved') NOT NULL DEFAULT 'pending',
    details JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    resolved_at DATETIME(6) NULL,
    UNIQUE KEY uq_catalog_link_review (catalog_work_component_id,conflict_type),
    INDEX idx_catalog_link_review_status (review_status,conflict_type),
    CONSTRAINT fk_catalog_link_review_component FOREIGN KEY (catalog_work_component_id)
      REFERENCES catalog_work_components(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO catalog_link_migration_review
    (catalog_work_component_id,item_library_id,work_type_id,conflict_type,details)
SELECT c.id,c.item_library_id,c.work_type_id,'service_multiple_activities',
       JSON_OBJECT('kept_component_id',d.keep_id,'reason','Only one active Work Activity may be linked to a Service')
FROM catalog_work_components c
JOIN (
    SELECT item_library_id,MIN(id) keep_id
    FROM catalog_work_components WHERE is_active=1
    GROUP BY item_library_id HAVING COUNT(*)>1
) d ON d.item_library_id=c.item_library_id
WHERE c.is_active=1 AND c.id<>d.keep_id;

UPDATE catalog_work_components c
JOIN (
    SELECT item_library_id,MIN(id) keep_id
    FROM catalog_work_components WHERE is_active=1
    GROUP BY item_library_id HAVING COUNT(*)>1
) d ON d.item_library_id=c.item_library_id
SET c.is_active=0
WHERE c.is_active=1 AND c.id<>d.keep_id;

INSERT IGNORE INTO catalog_link_migration_review
    (catalog_work_component_id,item_library_id,work_type_id,conflict_type,details)
SELECT c.id,c.item_library_id,c.work_type_id,'activity_multiple_services',
       JSON_OBJECT('kept_component_id',d.keep_id,'reason','Only one active Service may be linked to a Work Activity')
FROM catalog_work_components c
JOIN (
    SELECT work_type_id,MIN(id) keep_id
    FROM catalog_work_components WHERE is_active=1
    GROUP BY work_type_id HAVING COUNT(*)>1
) d ON d.work_type_id=c.work_type_id
WHERE c.is_active=1 AND c.id<>d.keep_id;

UPDATE catalog_work_components c
JOIN (
    SELECT work_type_id,MIN(id) keep_id
    FROM catalog_work_components WHERE is_active=1
    GROUP BY work_type_id HAVING COUNT(*)>1
) d ON d.work_type_id=c.work_type_id
SET c.is_active=0
WHERE c.is_active=1 AND c.id<>d.keep_id;

SET @has_active_service_link := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema=DATABASE() AND table_name='catalog_work_components' AND column_name='active_item_library_id'
);
SET @sql := IF(@has_active_service_link=0,
    'ALTER TABLE catalog_work_components
       ADD COLUMN active_item_library_id INT GENERATED ALWAYS AS (IF(is_active=1,item_library_id,NULL)) STORED,
       ADD COLUMN active_work_type_id INT GENERATED ALWAYS AS (IF(is_active=1,work_type_id,NULL)) STORED,
       ADD UNIQUE KEY uq_catalog_active_service_link (active_item_library_id),
       ADD UNIQUE KEY uq_catalog_active_activity_link (active_work_type_id)',
    'SELECT 1'
);
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

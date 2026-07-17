-- Remove the unused product SKU field and add indexes for bulk tax lookups.

SET @has_item_sku_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='item_library' AND index_name='idx_item_lib_sku');
SET @sql := IF(@has_item_sku_index>0, 'ALTER TABLE item_library DROP INDEX idx_item_lib_sku', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_item_sku := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='item_library' AND column_name='sku');
SET @sql := IF(@has_item_sku>0, 'ALTER TABLE item_library DROP COLUMN sku', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_tax_zip_range_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='tax_boundaries' AND index_name='idx_tax_boundaries_zip_range');
SET @sql := IF(@has_tax_zip_range_index=0, 'ALTER TABLE tax_boundaries ADD INDEX idx_tax_boundaries_zip_range (zip5_start,zip5_end)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_tax_county_active_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='tax_jurisdictions' AND index_name='idx_tax_jurisdiction_county_active');
SET @sql := IF(@has_tax_county_active_index=0, 'ALTER TABLE tax_jurisdictions ADD INDEX idx_tax_jurisdiction_county_active (state_fips,county_fips,jurisdiction_type,is_active)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @has_tax_state_code_index := (SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name='tax_jurisdictions' AND index_name='idx_tax_jurisdiction_state_code');
SET @sql := IF(@has_tax_state_code_index=0, 'ALTER TABLE tax_jurisdictions ADD INDEX idx_tax_jurisdiction_state_code (state_fips,jurisdiction_code,jurisdiction_type,is_active)', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

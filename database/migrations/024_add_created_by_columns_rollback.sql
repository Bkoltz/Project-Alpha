DELIMITER //
CREATE PROCEDURE _drop_col_if_exists(IN p_table VARCHAR(64), IN p_col VARCHAR(64))
BEGIN
    IF EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = p_table AND column_name = p_col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `', p_table, '` DROP COLUMN `', p_col, '`');
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL _drop_col_if_exists('quotes',   'created_by');
CALL _drop_col_if_exists('contracts','created_by');
CALL _drop_col_if_exists('invoices', 'created_by');
CALL _drop_col_if_exists('clients',  'created_by');
CALL _drop_col_if_exists('projects', 'created_by');

DROP PROCEDURE IF EXISTS _drop_col_if_exists;

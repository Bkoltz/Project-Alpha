-- Migration 030: Optional per-user document sender profiles.
-- Lets PDFs use the creator's contact info when enabled on that user's account.

DROP PROCEDURE IF EXISTS _add_user_sender_col_if_missing;

DELIMITER //
CREATE PROCEDURE _add_user_sender_col_if_missing(IN p_col VARCHAR(64), IN p_def TEXT)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = p_col
    ) THEN
        SET @sql = CONCAT('ALTER TABLE `users` ADD COLUMN `', p_col, '` ', p_def);
        PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
    END IF;
END //
DELIMITER ;

CALL _add_user_sender_col_if_missing('document_sender_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER force_password_reset');
CALL _add_user_sender_col_if_missing('document_sender_name', 'VARCHAR(255) NULL AFTER document_sender_enabled');
CALL _add_user_sender_col_if_missing('document_sender_company', 'VARCHAR(255) NULL AFTER document_sender_name');
CALL _add_user_sender_col_if_missing('document_sender_address_line1', 'VARCHAR(255) NULL AFTER document_sender_company');
CALL _add_user_sender_col_if_missing('document_sender_address_line2', 'VARCHAR(255) NULL AFTER document_sender_address_line1');
CALL _add_user_sender_col_if_missing('document_sender_city', 'VARCHAR(120) NULL AFTER document_sender_address_line2');
CALL _add_user_sender_col_if_missing('document_sender_state', 'VARCHAR(120) NULL AFTER document_sender_city');
CALL _add_user_sender_col_if_missing('document_sender_postal', 'VARCHAR(40) NULL AFTER document_sender_state');
CALL _add_user_sender_col_if_missing('document_sender_country', 'VARCHAR(120) NULL AFTER document_sender_postal');
CALL _add_user_sender_col_if_missing('document_sender_phone', 'VARCHAR(80) NULL AFTER document_sender_country');
CALL _add_user_sender_col_if_missing('document_sender_email', 'VARCHAR(255) NULL AFTER document_sender_phone');

DROP PROCEDURE IF EXISTS _add_user_sender_col_if_missing;

-- Preserve user-entered signature labels and ordering for contract documents.
SET @contract_signature_signer_title_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contract_signatures'
      AND column_name = 'signer_title'
);

SET @sql := IF(
    @contract_signature_signer_title_exists = 0,
    'ALTER TABLE contract_signatures ADD COLUMN signer_title VARCHAR(190) NULL AFTER signatory_type',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @contract_signature_display_order_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contract_signatures'
      AND column_name = 'display_order'
);

SET @sql := IF(
    @contract_signature_display_order_exists = 0,
    'ALTER TABLE contract_signatures ADD COLUMN display_order INT NOT NULL DEFAULT 0 AFTER signer_title',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @contract_signature_is_required_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'contract_signatures'
      AND column_name = 'is_required'
);

SET @sql := IF(
    @contract_signature_is_required_exists = 0,
    'ALTER TABLE contract_signatures ADD COLUMN is_required TINYINT(1) NOT NULL DEFAULT 1 AFTER display_order',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

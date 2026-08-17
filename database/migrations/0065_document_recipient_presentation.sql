-- Keep the internal client association separate from the recipient rendered on
-- quotes, contracts, and invoices. New organization-addressed documents hide
-- the contact by default; already-issued documents retain their presentation.

SET @quotes_show_contact_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'quotes'
      AND column_name = 'show_contact_on_document'
);
SET @sql := IF(@quotes_show_contact_exists = 0,
    'ALTER TABLE quotes ADD COLUMN show_contact_on_document TINYINT(1) NOT NULL DEFAULT 0 AFTER organization_id',
    'SELECT 1');
PREPARE document_recipient_stmt FROM @sql;
EXECUTE document_recipient_stmt;
DEALLOCATE PREPARE document_recipient_stmt;
SET @sql := IF(@quotes_show_contact_exists = 0,
    'UPDATE quotes SET show_contact_on_document=1 WHERE organization_id IS NOT NULL AND status<>''draft''',
    'SELECT 1');
PREPARE document_recipient_stmt FROM @sql;
EXECUTE document_recipient_stmt;
DEALLOCATE PREPARE document_recipient_stmt;

SET @contracts_show_contact_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'contracts'
      AND column_name = 'show_contact_on_document'
);
SET @sql := IF(@contracts_show_contact_exists = 0,
    'ALTER TABLE contracts ADD COLUMN show_contact_on_document TINYINT(1) NOT NULL DEFAULT 0 AFTER organization_id',
    'SELECT 1');
PREPARE document_recipient_stmt FROM @sql;
EXECUTE document_recipient_stmt;
DEALLOCATE PREPARE document_recipient_stmt;
SET @sql := IF(@contracts_show_contact_exists = 0,
    'UPDATE contracts SET show_contact_on_document=1 WHERE organization_id IS NOT NULL AND status<>''draft''',
    'SELECT 1');
PREPARE document_recipient_stmt FROM @sql;
EXECUTE document_recipient_stmt;
DEALLOCATE PREPARE document_recipient_stmt;

SET @invoices_show_contact_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices'
      AND column_name = 'show_contact_on_document'
);
SET @sql := IF(@invoices_show_contact_exists = 0,
    'ALTER TABLE invoices ADD COLUMN show_contact_on_document TINYINT(1) NOT NULL DEFAULT 0 AFTER organization_id',
    'SELECT 1');
PREPARE document_recipient_stmt FROM @sql;
EXECUTE document_recipient_stmt;
DEALLOCATE PREPARE document_recipient_stmt;
SET @sql := IF(@invoices_show_contact_exists = 0,
    'UPDATE invoices SET show_contact_on_document=1 WHERE organization_id IS NOT NULL AND status<>''draft''',
    'SELECT 1');
PREPARE document_recipient_stmt FROM @sql;
EXECUTE document_recipient_stmt;
DEALLOCATE PREPARE document_recipient_stmt;

-- Store an organization's general contact channels separately from any named
-- client/contact who belongs to it.

SET @organization_general_email_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'organizations'
      AND column_name = 'general_email'
);
SET @sql := IF(@organization_general_email_exists = 0,
    'ALTER TABLE organizations ADD COLUMN general_email VARCHAR(255) NULL AFTER name',
    'SELECT 1');
PREPARE organization_general_contact_stmt FROM @sql;
EXECUTE organization_general_contact_stmt;
DEALLOCATE PREPARE organization_general_contact_stmt;

SET @organization_general_phone_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'organizations'
      AND column_name = 'general_phone'
);
SET @sql := IF(@organization_general_phone_exists = 0,
    'ALTER TABLE organizations ADD COLUMN general_phone VARCHAR(50) NULL AFTER general_email',
    'SELECT 1');
PREPARE organization_general_contact_stmt FROM @sql;
EXECUTE organization_general_contact_stmt;
DEALLOCATE PREPARE organization_general_contact_stmt;

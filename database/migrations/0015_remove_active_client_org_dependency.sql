-- Organizations are client/customer records, not a required PA workspace scope.
-- Business-wide finance/forms records may therefore have organization_id = NULL.

SET @default_org_ids := (
    SELECT GROUP_CONCAT(id)
    FROM organizations
    WHERE LOWER(name) IN ('default organization', 'default org', 'default')
);

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'client_onboarding_invitations' AND constraint_name = 'fk_client_onboarding_owner');
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE client_onboarding_invitations DROP FOREIGN KEY fk_client_onboarding_owner', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE client_onboarding_invitations MODIFY organization_id INT NULL DEFAULT NULL;
ALTER TABLE client_onboarding_invitations ADD CONSTRAINT fk_client_onboarding_owner FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'expense_categories' AND constraint_name = 'fk_exp_cat_org');
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE expense_categories DROP FOREIGN KEY fk_exp_cat_org', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE expense_categories MODIFY organization_id INT NULL DEFAULT NULL;
ALTER TABLE expense_categories ADD CONSTRAINT fk_exp_cat_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'vendors' AND constraint_name = 'fk_vendor_org');
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE vendors DROP FOREIGN KEY fk_vendor_org', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE vendors MODIFY organization_id INT NULL DEFAULT NULL;
ALTER TABLE vendors ADD CONSTRAINT fk_vendor_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'receipts' AND constraint_name = 'fk_receipts_org');
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE receipts DROP FOREIGN KEY fk_receipts_org', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE receipts MODIFY organization_id INT NULL DEFAULT NULL;
ALTER TABLE receipts ADD CONSTRAINT fk_receipts_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'expenses' AND constraint_name = 'fk_exp_org');
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE expenses DROP FOREIGN KEY fk_exp_org', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE expenses MODIFY organization_id INT NULL DEFAULT NULL;
ALTER TABLE expenses ADD CONSTRAINT fk_exp_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'financial_assets' AND constraint_name = 'fk_fin_asset_org');
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE financial_assets DROP FOREIGN KEY fk_fin_asset_org', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE financial_assets MODIFY organization_id INT NULL DEFAULT NULL;
ALTER TABLE financial_assets ADD CONSTRAINT fk_fin_asset_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'mileage_logs' AND constraint_name = 'fk_mileage_org');
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE mileage_logs DROP FOREIGN KEY fk_mileage_org', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE mileage_logs MODIFY organization_id INT NULL DEFAULT NULL;
ALTER TABLE mileage_logs ADD CONSTRAINT fk_mileage_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'form_categories' AND constraint_name = 'fk_form_cat_org');
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE form_categories DROP FOREIGN KEY fk_form_cat_org', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE form_categories MODIFY organization_id INT NULL DEFAULT NULL;
ALTER TABLE form_categories ADD CONSTRAINT fk_form_cat_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

SET @fk_exists := (SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = DATABASE() AND table_name = 'form_documents' AND constraint_name = 'fk_form_docs_org');
SET @sql := IF(@fk_exists > 0, 'ALTER TABLE form_documents DROP FOREIGN KEY fk_form_docs_org', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
ALTER TABLE form_documents MODIFY organization_id INT NULL DEFAULT NULL;
ALTER TABLE form_documents ADD CONSTRAINT fk_form_docs_org FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE SET NULL;

ALTER TABLE audit_schedules MODIFY organization_id INT NULL DEFAULT NULL;

UPDATE expense_categories SET organization_id = NULL WHERE @default_org_ids IS NOT NULL AND FIND_IN_SET(organization_id, @default_org_ids);
UPDATE vendors SET organization_id = NULL WHERE @default_org_ids IS NOT NULL AND FIND_IN_SET(organization_id, @default_org_ids);
UPDATE receipts SET organization_id = NULL WHERE @default_org_ids IS NOT NULL AND FIND_IN_SET(organization_id, @default_org_ids);
UPDATE expenses SET organization_id = NULL WHERE @default_org_ids IS NOT NULL AND FIND_IN_SET(organization_id, @default_org_ids);
UPDATE financial_assets SET organization_id = NULL WHERE @default_org_ids IS NOT NULL AND FIND_IN_SET(organization_id, @default_org_ids);
UPDATE mileage_logs SET organization_id = NULL WHERE @default_org_ids IS NOT NULL AND FIND_IN_SET(organization_id, @default_org_ids);
UPDATE form_categories SET organization_id = NULL WHERE @default_org_ids IS NOT NULL AND FIND_IN_SET(organization_id, @default_org_ids);
UPDATE form_documents SET organization_id = NULL WHERE @default_org_ids IS NOT NULL AND FIND_IN_SET(organization_id, @default_org_ids);
UPDATE audit_schedules SET organization_id = NULL WHERE organization_id = 0 OR (@default_org_ids IS NOT NULL AND FIND_IN_SET(organization_id, @default_org_ids));
UPDATE client_onboarding_invitations SET organization_id = NULL WHERE @default_org_ids IS NOT NULL AND FIND_IN_SET(organization_id, @default_org_ids);

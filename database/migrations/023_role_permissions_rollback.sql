SET FOREIGN_KEY_CHECKS = 0;

-- Drop FK on user_organizations.role_id if it exists (MySQL does not support IF EXISTS for constraints)
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'user_organizations'
      AND constraint_name = 'fk_user_orgs_role_id'
);
SET @sql := IF(@fk_exists = 1,
    'ALTER TABLE user_organizations DROP FOREIGN KEY fk_user_orgs_role_id',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Drop user_organizations.role_id column if it exists
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'user_organizations'
      AND column_name = 'role_id'
);
SET @sql2 := IF(@col_exists = 1,
    'ALTER TABLE user_organizations DROP COLUMN role_id',
    'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Drop ACL tables
DROP TABLE IF EXISTS user_permissions_overrides;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS roles;

SET FOREIGN_KEY_CHECKS = 1;

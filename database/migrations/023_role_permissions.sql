-- Migration 023: Role-based access control — custom roles + permission matrix
-- Idempotent. Safe to run on every container boot.
-- Note: No explicit START TRANSACTION/COMMIT — MySQL DDL statements auto-commit
-- and cannot be wrapped in transactions. Each statement is idempotent on its own.

-- roles table
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    is_system TINYINT(1) NOT NULL DEFAULT 0,
    organization_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_name_org (name, organization_id),
    INDEX idx_roles_org (organization_id),
    INDEX idx_roles_system (is_system),
    CONSTRAINT fk_roles_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- role_permissions table
CREATE TABLE IF NOT EXISTS role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission VARCHAR(80) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_role_permission (role_id, permission),
    INDEX idx_rp_role (role_id),
    INDEX idx_rp_permission (permission),
    CONSTRAINT fk_rp_role
        FOREIGN KEY (role_id) REFERENCES roles(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_permissions_overrides table
CREATE TABLE IF NOT EXISTS user_permissions_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    organization_id INT NULL,
    permission VARCHAR(80) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_org_permission (user_id, organization_id, permission),
    INDEX idx_upo_user (user_id),
    INDEX idx_upo_permission (permission),
    CONSTRAINT fk_upo_user
        FOREIGN KEY (user_id) REFERENCES users(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_upo_organization
        FOREIGN KEY (organization_id) REFERENCES organizations(id)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed system roles (idempotent)
INSERT INTO roles (id, name, description, is_system, organization_id) VALUES
    (1, 'admin', 'Super-admin bypass; manages app, all orgs, all data.', 1, NULL),
    (2, 'owner', 'Full access within assigned orgs.', 1, NULL),
    (3, 'staff', 'Operational access within assigned orgs; no settings/users/billing.', 1, NULL),
    (4, 'member', 'Read-mostly within assigned orgs; record-level scoping.', 1, NULL)
ON DUPLICATE KEY UPDATE
    description = VALUES(description),
    is_system = VALUES(is_system);

-- role_permissions seed (idempotent via ON DUPLICATE KEY)
INSERT INTO role_permissions (role_id, permission, allowed) VALUES
    (1, '*', 1),
    (2, 'quotes.*', 1), (2, 'contracts.*', 1), (2, 'invoices.*', 1),
    (2, 'clients.*', 1), (2, 'projects.*', 1), (2, 'jobs.*', 1),
    (2, 'financial.view', 1), (2, 'financial.manage', 1), (2, 'financial.export', 1), (2, 'financial.audit', 1),
    (2, 'reports.view', 1), (2, 'settings.view', 1), (2, 'settings.manage', 1),
    (2, 'users.view', 1), (2, 'users.manage', 1), (2, 'users.reset_password', 1),
    (2, 'api_keys.*', 1), (2, 'billing.*', 1), (2, 'organizations.*', 1),
    (2, 'public_links.*', 1), (2, 'time_tracking.*', 1), (2, 'profile.*', 1),
    (3, 'quotes.*', 1), (3, 'contracts.*', 1), (3, 'invoices.*', 1),
    (3, 'clients.*', 1), (3, 'projects.*', 1), (3, 'jobs.*', 1),
    (3, 'financial.view', 1), (3, 'financial.manage', 1), (3, 'financial.export', 0),
    (3, 'financial.audit', 0), (3, 'reports.view', 1),
    (3, 'settings.view', 0), (3, 'settings.manage', 0),
    (3, 'users.view', 0), (3, 'users.manage', 0), (3, 'users.reset_password', 0),
    (3, 'api_keys.*', 0), (3, 'billing.*', 0), (3, 'organizations.*', 0),
    (3, 'public_links.*', 1), (3, 'time_tracking.*', 1), (3, 'profile.*', 1),
    (4, 'quotes.view', 1), (4, 'contracts.view', 1), (4, 'invoices.view', 1),
    (4, 'clients.view', 1), (4, 'projects.view', 1), (4, 'jobs.view', 1),
    (4, 'financial.view', 1), (4, 'financial.manage', 0), (4, 'financial.export', 0),
    (4, 'financial.audit', 0), (4, 'reports.view', 0),
    (4, 'settings.view', 0), (4, 'settings.manage', 0),
    (4, 'users.view', 0), (4, 'users.manage', 0), (4, 'users.reset_password', 0),
    (4, 'api_keys.*', 0), (4, 'billing.*', 0), (4, 'organizations.*', 0),
    (4, 'public_links.view', 1), (4, 'public_links.create', 0), (4, 'public_links.revoke', 0),
    (4, 'time_tracking.view', 1), (4, 'time_tracking.manage', 0),
    (4, 'profile.*', 1)
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

-- Idempotently add user_organizations.role_id column
SET @col_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'user_organizations'
      AND column_name = 'role_id'
);
SET @sql := IF(@col_exists = 0,
    'ALTER TABLE user_organizations
     ADD COLUMN role_id INT NULL AFTER role,
     ADD INDEX idx_user_orgs_role_id (role_id)',
    'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Add FK only if not present
SET @fk_exists := (
    SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE()
      AND table_name = 'user_organizations'
      AND constraint_name = 'fk_user_orgs_role_id'
);
SET @sql2 := IF(@fk_exists = 0,
    'ALTER TABLE user_organizations
     ADD CONSTRAINT fk_user_orgs_role_id
     FOREIGN KEY (role_id) REFERENCES roles(id)
     ON DELETE SET NULL ON UPDATE CASCADE',
    'SELECT 1');
PREPARE stmt2 FROM @sql2;
EXECUTE stmt2;
DEALLOCATE PREPARE stmt2;

-- Backfill role_id from ENUM role for existing rows
UPDATE user_organizations uo
JOIN roles r ON r.name = uo.role AND r.is_system = 1
SET uo.role_id = r.id
WHERE uo.role_id IS NULL;

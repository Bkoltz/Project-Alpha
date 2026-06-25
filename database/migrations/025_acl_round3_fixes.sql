-- Migration 025: ACL round 3 — backfills + delete-key removal + void keys
-- Idempotent. Safe to run on every container boot.
-- Note: No explicit START TRANSACTION/COMMIT — MySQL DDL statements auto-commit
-- and cannot be wrapped in transactions. Each statement is idempotent on its own.

-- Some older databases still have user_organizations.role as ENUM('admin','user').
-- Convert to VARCHAR so we can safely normalize legacy values regardless of current enum.
ALTER TABLE user_organizations MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'member';

-- Normalize legacy form value 'user' (and any unknowns) to the actual DB role 'member'
UPDATE user_organizations SET role = 'member' WHERE role NOT IN ('owner','admin','member') OR role IS NULL;

-- Backfill role_id from role text for existing rows
UPDATE user_organizations uo
JOIN roles r ON r.name = uo.role AND r.is_system = 1
SET uo.role_id = r.id
WHERE uo.role_id IS NULL;

-- Hard-fallback: any row still missing role_id gets the system 'member' role.
UPDATE user_organizations uo
JOIN roles r ON r.name = 'member' AND r.is_system = 1
SET uo.role_id = r.id
WHERE uo.role_id IS NULL;

-- Backfill created_by for record-level member scoping (default to system admin)
UPDATE quotes    SET created_by = 1 WHERE created_by IS NULL;
UPDATE contracts SET created_by = 1 WHERE created_by IS NULL;
UPDATE invoices  SET created_by = 1 WHERE created_by IS NULL;
UPDATE clients   SET created_by = 1 WHERE created_by IS NULL;
UPDATE projects  SET created_by = 1 WHERE created_by IS NULL;

-- Backfill organization_id where missing (default to first organization)
UPDATE quotes   SET organization_id = (SELECT id FROM organizations ORDER BY id ASC LIMIT 1) WHERE organization_id IS NULL;
UPDATE invoices SET organization_id = (SELECT id FROM organizations ORDER BY id ASC LIMIT 1) WHERE organization_id IS NULL;

-- Remove delete permission keys (superseded by archive/void workflows)
DELETE FROM role_permissions          WHERE permission IN ('quotes.delete','contracts.delete','invoices.delete','jobs.delete');
DELETE FROM user_permissions_overrides WHERE permission IN ('quotes.delete','contracts.delete','invoices.delete','jobs.delete');

-- Seed void permission keys for system roles (owner, staff, member)
INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'contracts.void', CASE WHEN name IN ('owner','staff','member') THEN 1 ELSE 0 END FROM roles WHERE is_system = 1
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'invoices.void', CASE WHEN name IN ('owner','staff','member') THEN 1 ELSE 0 END FROM roles WHERE is_system = 1
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

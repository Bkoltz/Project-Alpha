-- Migration 028: Seed payments permissions for all system roles
-- Idempotent. Safe to run on every container boot.
-- Note: No explicit START TRANSACTION/COMMIT — MySQL DDL statements auto-commit
-- and cannot be wrapped in transactions. Each statement is idempotent on its own.

-- Ensure all system roles can view and create payments.
INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'payments.view', 1 FROM roles WHERE is_system = 1
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'payments.create', 1 FROM roles WHERE is_system = 1
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

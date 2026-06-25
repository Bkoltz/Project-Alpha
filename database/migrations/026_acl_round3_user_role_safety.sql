-- Migration 026: ACL round 3 — safety net for legacy user_organizations rows
-- Idempotent. Runs on every container boot. Safe to re-run.
--
-- Some existing deployments ran an older Migration 025 that did not convert the
-- user_organizations.role column from ENUM('admin','user'). This migration
-- converts it to VARCHAR, normalizes legacy 'user' values, and ensures role_id
-- is populated for every user.

-- Convert role column to VARCHAR so legacy enum values can be normalized safely
ALTER TABLE user_organizations MODIFY COLUMN role VARCHAR(50) NOT NULL DEFAULT 'member';

-- Normalize legacy/unknown role text to 'member'
UPDATE user_organizations SET role = 'member' WHERE role NOT IN ('owner','admin','member') OR role IS NULL;

-- Backfill role_id from role text where still NULL
UPDATE user_organizations uo
JOIN roles r ON r.name = uo.role AND r.is_system = 1
SET uo.role_id = r.id
WHERE uo.role_id IS NULL;

-- Final hard-fallback: any row still missing role_id gets the system 'member' role
UPDATE user_organizations uo
JOIN roles r ON r.name = 'member' AND r.is_system = 1
SET uo.role_id = r.id
WHERE uo.role_id IS NULL;

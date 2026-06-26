-- Migration 027: Update member role defaults + backfill organization_id
-- Idempotent. Safe to run on every container boot.
-- Note: No explicit START TRANSACTION/COMMIT — MySQL DDL statements auto-commit
-- and cannot be wrapped in transactions. Each statement is idempotent on its own.

-- Update member role defaults to match the user's June 2026 business logic.
-- Allow all quotes, contracts, invoices, clients, projects, jobs, organizations (view/manage only),
-- and public_links permissions. Deny financial, reports, billing, users, api_keys, settings,
-- time_tracking, and 2fa permissions. Profile permissions remain allowed.
INSERT INTO role_permissions (role_id, permission, allowed) VALUES
    (4, 'quotes.view', 1), (4, 'quotes.create', 1), (4, 'quotes.edit', 1), (4, 'quotes.send', 1), (4, 'quotes.approve', 1), (4, 'quotes.reject', 1),
    (4, 'contracts.view', 1), (4, 'contracts.create', 1), (4, 'contracts.edit', 1), (4, 'contracts.sign', 1), (4, 'contracts.complete', 1), (4, 'contracts.void', 1), (4, 'contracts.send', 1),
    (4, 'invoices.view', 1), (4, 'invoices.create', 1), (4, 'invoices.edit', 1), (4, 'invoices.void', 1), (4, 'invoices.mark_paid', 1), (4, 'invoices.send', 1),
    (4, 'clients.view', 1), (4, 'clients.create', 1), (4, 'clients.edit', 1), (4, 'clients.delete', 1), (4, 'clients.purge', 1), (4, 'clients.restore', 1),
    (4, 'projects.view', 1), (4, 'projects.create', 1), (4, 'projects.edit', 1), (4, 'projects.delete', 1), (4, 'projects.search', 1),
    (4, 'jobs.view', 1), (4, 'jobs.edit', 1), (4, 'jobs.search', 1),
    (4, 'organizations.view', 1), (4, 'organizations.manage', 1),
    (4, 'public_links.view', 1), (4, 'public_links.create', 1), (4, 'public_links.revoke', 1), (4, 'public_links.manage', 1),
    (4, 'time_tracking.view', 0), (4, 'time_tracking.manage', 0),
    (4, 'reports.view', 0),
    (4, 'financial.view', 0), (4, 'financial.manage', 0), (4, 'financial.export', 0), (4, 'financial.audit', 0),
    (4, 'billing.view', 0), (4, 'billing.manage', 0),
    (4, 'users.view', 0), (4, 'users.manage', 0), (4, 'users.reset_password', 0), (4, 'users.delete', 0),
    (4, 'api_keys.view', 0), (4, 'api_keys.manage', 0),
    (4, 'settings.view', 0), (4, 'settings.manage', 0),
    (4, '2fa.manage', 0),
    (4, 'profile.view', 1), (4, 'profile.edit', 1)
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

-- Remove any legacy member-role permissions that are no longer part of the desired matrix.
DELETE FROM role_permissions
WHERE role_id = 4
  AND permission IN (
      'quotes.delete',
      'contracts.delete',
      'invoices.delete',
      'organizations.delete'
  );

-- Backfill organization_id where missing on record-scoped tables.
-- Uses the record's linked organization when available; otherwise falls back to the first organization.
UPDATE clients   SET organization_id = COALESCE(organization_id, (SELECT id FROM organizations ORDER BY id ASC LIMIT 1)) WHERE organization_id IS NULL;
UPDATE projects  SET organization_id = COALESCE(organization_id, (SELECT id FROM organizations ORDER BY id ASC LIMIT 1)) WHERE organization_id IS NULL;
UPDATE contracts SET organization_id = COALESCE(organization_id, (SELECT id FROM organizations ORDER BY id ASC LIMIT 1)) WHERE organization_id IS NULL;
UPDATE quotes    SET organization_id = COALESCE(organization_id, (SELECT id FROM organizations ORDER BY id ASC LIMIT 1)) WHERE organization_id IS NULL;
UPDATE invoices  SET organization_id = COALESCE(organization_id, (SELECT id FROM organizations ORDER BY id ASC LIMIT 1)) WHERE organization_id IS NULL;

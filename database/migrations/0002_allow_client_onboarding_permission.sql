INSERT INTO role_permissions (role_id, permission, allowed)
SELECT id, 'clients.onboarding', 1
FROM roles
ON DUPLICATE KEY UPDATE allowed = VALUES(allowed);

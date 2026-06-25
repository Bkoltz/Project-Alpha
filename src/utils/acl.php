<?php
// src/utils/acl.php
// Permission resolver + data scoping helpers.
// Resolves effective permissions from per-org role + overrides, with super-admin bypass.

function get_active_org_id(): int
{
    return (int)($_SESSION['user']['active_org_id'] ?? 0);
}

function user_role_on_org(PDO $pdo, int $userId, int $orgId): ?string
{
    static $cache = [];
    $key = "{$userId}:{$orgId}";
    if (isset($cache[$key])) return $cache[$key];
    try {
        $stmt = $pdo->prepare('SELECT r.name FROM user_organizations uo JOIN roles r ON r.id = uo.role_id WHERE uo.user_id = ? AND uo.organization_id = ? LIMIT 1');
        $stmt->execute([$userId, $orgId]);
        $cache[$key] = $stmt->fetchColumn() ?: null;
    } catch (Throwable $e) {
        // ACL tables may not exist yet (pre-migration)
        $cache[$key] = null;
    }
    return $cache[$key];
}

function user_org_ids(PDO $pdo, int $userId): array
{
    static $cache = [];
    if (isset($cache[$userId])) return $cache[$userId];
    $stmt = $pdo->prepare('SELECT organization_id FROM user_organizations WHERE user_id = ?');
    $stmt->execute([$userId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    $cache[$userId] = $ids;
    return $cache[$userId];
}

function compute_permissions_hash(PDO $pdo, int $userId, int $activeOrgId): string
{
    try {
        $roleId = null;
        $stmt = $pdo->prepare('SELECT role_id FROM user_organizations WHERE user_id = ? AND organization_id = ? LIMIT 1');
        $stmt->execute([$userId, $activeOrgId]);
        $roleId = $stmt->fetchColumn();
    } catch (Throwable $e) {
        // role_id column may not exist yet (pre-ACL migration)
        $roleId = null;
    }

    try {
        $stmt = $pdo->prepare('SELECT permission, allowed FROM user_permissions_overrides WHERE user_id = ? AND (organization_id = ? OR organization_id IS NULL) ORDER BY permission');
        $stmt->execute([$userId, $activeOrgId]);
        $overrides = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // user_permissions_overrides table may not exist yet (pre-ACL migration)
        $overrides = [];
    }

    return hash('sha256', json_encode(['role_id' => $roleId, 'overrides' => $overrides]));
}

function user_permissions(PDO $pdo, int $userId, ?int $activeOrgId = null): array
{
    static $cache = [];
    $activeOrgId = $activeOrgId ?: get_active_org_id();
    $key = "{$userId}:{$activeOrgId}";
    if (isset($cache[$key])) return $cache[$key];

    $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $globalRole = $stmt->fetchColumn() ?: 'user';

    if ($globalRole === 'admin') {
        $cache[$key] = ['*' => true];
        return $cache[$key];
    }

    $permissions = [];

    try {
        $stmt = $pdo->prepare('SELECT rp.permission, rp.allowed
            FROM user_organizations uo
            JOIN roles r ON r.id = uo.role_id
            JOIN role_permissions rp ON rp.role_id = r.id
            WHERE uo.user_id = ? AND uo.organization_id = ?');
        $stmt->execute([$userId, $activeOrgId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $permissions[$row['permission']] = (bool)$row['allowed'];
        }
    } catch (Throwable $e) {
        // ACL tables may not exist yet (pre-migration) — return empty permissions
    }

    try {
        $stmt = $pdo->prepare('SELECT permission, allowed
            FROM user_permissions_overrides
            WHERE user_id = ? AND (organization_id = ? OR organization_id IS NULL)');
        $stmt->execute([$userId, $activeOrgId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $permissions[$row['permission']] = (bool)$row['allowed'];
        }
    } catch (Throwable $e) {
        // user_permissions_overrides table may not exist yet (pre-migration)
    }

    $cache[$key] = $permissions;
    return $permissions;
}

function user_can(PDO $pdo, int $userId, string $permission, ?int $activeOrgId = null): bool
{
    $perms = user_permissions($pdo, $userId, $activeOrgId);
    if (!empty($perms['*'])) return true;
    if (isset($perms[$permission])) return $perms[$permission];
    // Check nested wildcards (e.g., quotes.* for quotes.view, or financial.* for financial.export)
    $parts = explode('.', $permission);
    while (count($parts) > 1) {
        array_pop($parts);
        $wildcard = implode('.', $parts) . '.*';
        if (isset($perms[$wildcard])) return $perms[$wildcard];
    }
    return false;
}

function scope_clause(PDO $pdo, string $tableAlias, int $userId): array
{
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return ['', []];
    }
    $activeOrgId = get_active_org_id();
    if ($activeOrgId === 0) {
        return [' AND 1=0 ', []];
    }
    if (user_role_on_org($pdo, $userId, $activeOrgId) === 'member') {
        return [
            " AND {$tableAlias}.organization_id = ? AND {$tableAlias}.created_by = ? ",
            [$activeOrgId, $userId]
        ];
    }
    return [
        " AND {$tableAlias}.organization_id = ? ",
        [$activeOrgId]
    ];
}

function can_access_record(PDO $pdo, string $table, int $recordId, int $userId): bool
{
    if (($_SESSION['user']['role'] ?? '') === 'admin') return true;
    $activeOrgId = get_active_org_id();
    if ($activeOrgId === 0) return false;
    $isMember = user_role_on_org($pdo, $userId, $activeOrgId) === 'member';
    $stmt = $pdo->prepare("SELECT organization_id, created_by FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$recordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if ((int)$row['organization_id'] !== $activeOrgId) return false;
    if ($isMember && (int)$row['created_by'] !== $userId) return false;
    return true;
}

function role_id_by_name(PDO $pdo, string $roleName, ?int $orgId = null): ?int
{
    try {
        $stmt = $pdo->prepare(
            'SELECT id FROM roles
             WHERE name = ? AND (organization_id = ? OR organization_id IS NULL)
             ORDER BY (organization_id IS NULL) ASC, is_system DESC
             LIMIT 1'
        );
        $stmt->execute([$roleName, $orgId]);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int)$id : null;
    } catch (Throwable $e) {
        return null;
    }
}

function require_record_ownership(PDO $pdo, string $table, int $recordId): void
{
    if (!can_access_record($pdo, $table, $recordId, (int)($_SESSION['user']['id'] ?? 0))) {
        require_once __DIR__ . '/acl_middleware.php';
        deny_response($table . '/view');
    }
}

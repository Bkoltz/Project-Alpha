<?php
// src/utils/acl.php
// Permission resolver + data scoping helpers for the 0.5.0 single-business model.
// PA users belong to the app/business install. `organizations` are customer records.

function get_active_org_id(): int
{
    return (int)($_SESSION['user']['active_org_id'] ?? 0);
}

function active_or_default_org_id(PDO $pdo): int
{
    $activeOrgId = get_active_org_id();
    if ($activeOrgId > 0) {
        return $activeOrgId;
    }

    try {
        $id = $pdo->query('SELECT id FROM organizations ORDER BY id ASC LIMIT 1')->fetchColumn();
        return $id !== false ? (int)$id : 0;
    } catch (Throwable $e) {
        return 0;
    }
}

function acl_default_user_org_id(PDO $pdo, int $userId): int
{
    return 0;
}

function acl_effective_permission_org_id(PDO $pdo, int $userId, ?int $activeOrgId = null): int
{
    $activeOrgId = (int)($activeOrgId ?: get_active_org_id());
    return $activeOrgId > 0 ? $activeOrgId : 0;
}

function acl_user_role(PDO $pdo, int $userId): string
{
    static $cache = [];
    if (isset($cache[$userId])) {
        return $cache[$userId];
    }

    try {
        $stmt = $pdo->prepare('SELECT role FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $role = (string)($stmt->fetchColumn() ?: 'member');
    } catch (Throwable $e) {
        $role = 'member';
    }

    $cache[$userId] = in_array($role, ['admin', 'owner', 'staff', 'member'], true) ? $role : 'member';
    return $cache[$userId];
}

function user_role_on_org(PDO $pdo, int $userId, int $orgId): ?string
{
    return acl_user_role($pdo, $userId);
}

function user_org_ids(PDO $pdo, int $userId): array
{
    return [];
}

function compute_permissions_hash(PDO $pdo, int $userId, int $activeOrgId): string
{
    $activeOrgId = acl_effective_permission_org_id($pdo, $userId, $activeOrgId);
    $role = acl_user_role($pdo, $userId);
    $roleId = role_id_by_name($pdo, $role, null);

    try {
        $stmt = $pdo->prepare('SELECT permission, allowed FROM user_permissions_overrides WHERE user_id = ? AND (organization_id = ? OR organization_id IS NULL) ORDER BY permission');
        $stmt->execute([$userId, $activeOrgId]);
        $overrides = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $overrides = [];
    }

    return hash('sha256', json_encode(['role' => $role, 'role_id' => $roleId, 'overrides' => $overrides]));
}

function user_permissions(PDO $pdo, int $userId, ?int $activeOrgId = null): array
{
    static $cache = [];
    $activeOrgId = acl_effective_permission_org_id($pdo, $userId, (int)($activeOrgId ?: get_active_org_id()));
    $role = acl_user_role($pdo, $userId);
    $key = "{$userId}:{$activeOrgId}:{$role}";
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if ($role === 'admin') {
        $cache[$key] = ['*' => true];
        return $cache[$key];
    }

    $permissions = [];

    try {
        $roleId = role_id_by_name($pdo, $role, null);
        if ($roleId !== null) {
            $stmt = $pdo->prepare('SELECT permission, allowed FROM role_permissions WHERE role_id = ?');
            $stmt->execute([$roleId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $permissions[$row['permission']] = (bool)$row['allowed'];
            }
        }
    } catch (Throwable $e) {
        $permissions = [];
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
        // Keep role-derived permissions if overrides are unavailable.
    }

    $cache[$key] = $permissions;
    return $permissions;
}

function user_can(PDO $pdo, int $userId, string $permission, ?int $activeOrgId = null): bool
{
    $perms = user_permissions($pdo, $userId, $activeOrgId);
    if (!empty($perms['*'])) {
        return true;
    }
    if (isset($perms[$permission])) {
        return $perms[$permission];
    }

    $parts = explode('.', $permission);
    while (count($parts) > 1) {
        array_pop($parts);
        $wildcard = implode('.', $parts) . '.*';
        if (isset($perms[$wildcard])) {
            return $perms[$wildcard];
        }
    }

    return false;
}

function acl_user_has_org_wide_scope(PDO $pdo, int $userId, ?int $activeOrgId = null): bool
{
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return true;
    }

    return in_array(acl_user_role($pdo, $userId), ['owner', 'staff'], true);
}

function acl_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . ':' . $column;
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        $cache[$key] = false;
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
             LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        $cache[$key] = $stmt->fetchColumn() !== false;
    } catch (Throwable $e) {
        $cache[$key] = false;
    }

    return $cache[$key];
}

function scope_clause(PDO $pdo, string $tableAlias, int $userId): array
{
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return ['', []];
    }

    $activeOrgId = get_active_org_id();
    if ($activeOrgId === 0) {
        return acl_user_has_org_wide_scope($pdo, $userId, 0) ? ['', []] : ['1=0', []];
    }

    if (acl_user_has_org_wide_scope($pdo, $userId, $activeOrgId)) {
        return ["{$tableAlias}.organization_id = ?", [$activeOrgId]];
    }

    return ["{$tableAlias}.organization_id = ? AND {$tableAlias}.created_by = ?", [$activeOrgId, $userId]];
}

function can_access_record(PDO $pdo, string $table, int $recordId, int $userId): bool
{
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return true;
    }
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
        return false;
    }

    $activeOrgId = get_active_org_id();
    if ($activeOrgId === 0 && acl_user_has_org_wide_scope($pdo, $userId, 0)) {
        return true;
    }
    if ($activeOrgId === 0) {
        return false;
    }

    if ($table === 'organizations') {
        return acl_user_has_org_wide_scope($pdo, $userId, $activeOrgId);
    }

    $select = 'organization_id';
    if (acl_table_has_column($pdo, $table, 'created_by')) {
        $select .= ', created_by';
    }

    $stmt = $pdo->prepare("SELECT {$select} FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$recordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || (int)$row['organization_id'] !== $activeOrgId) {
        return false;
    }

    if (acl_user_has_org_wide_scope($pdo, $userId, $activeOrgId)) {
        return true;
    }

    return isset($row['created_by']) && (int)$row['created_by'] === $userId;
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

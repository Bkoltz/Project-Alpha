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

    // Safety net: if user_organizations.role_id is NULL, resolve and persist it from text role / member fallback,
    // then re-read so the hash reflects the corrected state. This prevents session-staleness
    // redirect loops when legacy data has role_id=NULL.
    if ($roleId === null || $roleId === '') {
        try {
            $stmt = $pdo->prepare('SELECT id, role, role_id FROM user_organizations WHERE user_id = ? AND organization_id = ? LIMIT 1');
            $stmt->execute([$userId, $activeOrgId]);
            $uo = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($uo && ($uo['role_id'] === null || $uo['role_id'] === '')) {
                $resolved = role_id_by_name($pdo, in_array($uo['role'], ['owner','admin','member','staff']) ? $uo['role'] : 'member', null);
                if ($resolved === null) {
                    $fallback = $pdo->query("SELECT id FROM roles WHERE name='member' AND is_system=1 LIMIT 1")->fetchColumn();
                    $resolved = $fallback !== false ? (int)$fallback : null;
                }
                if ($resolved !== null) {
                    $pdo->prepare('UPDATE user_organizations SET role_id = ?, role = ? WHERE id = ?')->execute([$resolved, in_array($uo['role'], ['owner','admin','member','staff']) ? $uo['role'] : 'member', $uo['id']]);
                    $roleId = $resolved;
                }
            }
        } catch (Throwable $e) {}
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

    // Safety net: if user_organizations.role_id is NULL (stale data / failed migration),
    // resolve and persist the correct role_id from the text role, falling back to 'member'.
    // Then re-run the permission load so the same request benefits from the fix.
    $healed = false;
    try {
        $stmt = $pdo->prepare('SELECT id, role, role_id FROM user_organizations WHERE user_id = ? AND organization_id = ? LIMIT 1');
        $stmt->execute([$userId, $activeOrgId]);
        $uo = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($uo && ($uo['role_id'] === null || $uo['role_id'] === '')) {
            $resolved = role_id_by_name($pdo, in_array($uo['role'], ['owner','admin','member','staff']) ? $uo['role'] : 'member', null);
            if ($resolved === null) {
                $fallback = $pdo->query("SELECT id FROM roles WHERE name='member' AND is_system=1 LIMIT 1")->fetchColumn();
                $resolved = $fallback !== false ? (int)$fallback : null;
            }
            if ($resolved !== null) {
                $pdo->prepare('UPDATE user_organizations SET role_id = ?, role = ? WHERE id = ?')->execute([$resolved, in_array($uo['role'], ['owner','admin','member','staff']) ? $uo['role'] : 'member', $uo['id']]);
                $healed = true;
            }
        }
    } catch (Throwable $e) {
        // ACL tables may not exist yet (pre-migration)
    }

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

    // If we just healed role_id, the first permission query above may have run before
    // the UPDATE took effect in the same transaction/connection. Run it once more.
    if ($healed) {
        try {
            $permissions = [];
            $stmt = $pdo->prepare('SELECT rp.permission, rp.allowed
                FROM user_organizations uo
                JOIN roles r ON r.id = uo.role_id
                JOIN role_permissions rp ON rp.role_id = r.id
                WHERE uo.user_id = ? AND uo.organization_id = ?');
            $stmt->execute([$userId, $activeOrgId]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $permissions[$row['permission']] = (bool)$row['allowed'];
            }
        } catch (Throwable $e) {}
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

function acl_user_has_org_wide_scope(PDO $pdo, int $userId, ?int $activeOrgId = null): bool
{
    if (($_SESSION['user']['role'] ?? '') === 'admin') {
        return true;
    }

    $activeOrgId = $activeOrgId ?: get_active_org_id();
    if ($activeOrgId === 0) {
        return false;
    }

    $roleName = user_role_on_org($pdo, $userId, $activeOrgId);
    return in_array($roleName, ['admin', 'owner'], true);
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
        return ['1=0', []];
    }
    if (acl_user_has_org_wide_scope($pdo, $userId, $activeOrgId)) {
        return [
            "{$tableAlias}.organization_id = ?",
            [$activeOrgId]
        ];
    }

    return [
        "{$tableAlias}.organization_id = ? AND {$tableAlias}.created_by = ?",
        [$activeOrgId, $userId]
    ];
}

function can_access_record(PDO $pdo, string $table, int $recordId, int $userId): bool
{
    if (($_SESSION['user']['role'] ?? '') === 'admin') return true;
    $activeOrgId = get_active_org_id();
    if ($activeOrgId === 0) return false;

    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;

    if ($table === 'organizations') {
        return in_array($recordId, user_org_ids($pdo, $userId), true);
    }

    $select = 'organization_id';
    if (acl_table_has_column($pdo, $table, 'created_by')) {
        $select .= ', created_by';
    }

    $stmt = $pdo->prepare("SELECT {$select} FROM {$table} WHERE id = ? LIMIT 1");
    $stmt->execute([$recordId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) return false;
    if ((int)$row['organization_id'] !== $activeOrgId) return false;
    if (acl_user_has_org_wide_scope($pdo, $userId, $activeOrgId)) return true;
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

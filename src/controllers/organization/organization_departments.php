<?php
// src/controllers/organization/organization_departments.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$action = (string)($_POST['action'] ?? '');
$organizationId = (int)($_POST['organization_id'] ?? 0);
$redirect = '/?page=organization/organization-view&id=' . max(0, $organizationId);

try {
    if ($organizationId <= 0) {
        throw new RuntimeException('Invalid organization');
    }
    require_record_ownership($pdo, 'organizations', $organizationId);

    if ($action === 'save_link_strategy') {
        $strategy = (string)($_POST['link_strategy'] ?? 'department_links_only');
        if (!in_array($strategy, ['department_links_only', 'overall_folder', 'shared_folder'], true)) {
            $strategy = 'department_links_only';
        }
        $stmt = $pdo->prepare('UPDATE organizations SET link_strategy = ?, updated_at = NOW() WHERE id = ?');
        $stmt->execute([$strategy, $organizationId]);
        $flag = 'link_strategy_saved=1';
    } elseif ($action === 'save_department') {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        $folderName = trim((string)($_POST['folder_name'] ?? ''));
        $aliasesText = trim((string)($_POST['folder_aliases'] ?? ''));
        $resolverMode = (string)($_POST['resolver_mode'] ?? 'auto_attach');
        $notes = trim((string)($_POST['notes'] ?? ''));

        if ($name === '') {
            throw new RuntimeException('Department name is required');
        }
        if (!in_array($resolverMode, ['auto_attach', 'review', 'manual_only', 'excluded'], true)) {
            $resolverMode = 'manual_only';
        }

        $aliases = [];
        foreach (preg_split('/\r\n|\r|\n/', $aliasesText) ?: [] as $alias) {
            $alias = trim($alias);
            if ($alias !== '') {
                $aliases[] = $alias;
            }
        }
        $aliases = array_values(array_unique($aliases));
        $aliasesJson = $aliases ? json_encode($aliases, JSON_UNESCAPED_SLASHES) : null;

        if ($departmentId > 0) {
            $owner = $pdo->prepare('SELECT organization_id FROM organization_departments WHERE id = ? LIMIT 1');
            $owner->execute([$departmentId]);
            if ((int)($owner->fetchColumn() ?: 0) !== $organizationId) {
                throw new RuntimeException('Department not found');
            }
            $stmt = $pdo->prepare('
                UPDATE organization_departments
                SET name = ?, folder_name = ?, folder_aliases = ?, resolver_mode = ?, notes = ?, updated_at = NOW()
                WHERE id = ? AND organization_id = ?
            ');
            $stmt->execute([$name, $folderName !== '' ? $folderName : null, $aliasesJson, $resolverMode, $notes !== '' ? $notes : null, $departmentId, $organizationId]);
            $flag = 'department_saved=1';
        } else {
            $stmt = $pdo->prepare('
                INSERT INTO organization_departments (organization_id, name, folder_name, folder_aliases, resolver_mode, notes)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$organizationId, $name, $folderName !== '' ? $folderName : null, $aliasesJson, $resolverMode, $notes !== '' ? $notes : null]);
            $flag = 'department_created=1';
        }
    } elseif ($action === 'delete_department') {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        if ($departmentId <= 0) {
            throw new RuntimeException('Invalid department');
        }
        $stmt = $pdo->prepare('DELETE FROM organization_departments WHERE id = ? AND organization_id = ?');
        $stmt->execute([$departmentId, $organizationId]);
        $flag = 'department_deleted=1';
    } elseif ($action === 'assign_contact') {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($departmentId <= 0 || $clientId <= 0) {
            throw new RuntimeException('Invalid department contact');
        }
        $dept = $pdo->prepare('SELECT 1 FROM organization_departments WHERE id = ? AND organization_id = ? LIMIT 1');
        $dept->execute([$departmentId, $organizationId]);
        if (!$dept->fetchColumn()) {
            throw new RuntimeException('Department not found');
        }
        $client = $pdo->prepare('SELECT 1 FROM clients WHERE id = ? AND organization_id = ? AND archived = 0 LIMIT 1');
        $client->execute([$clientId, $organizationId]);
        if (!$client->fetchColumn()) {
            throw new RuntimeException('Client is not in this organization');
        }
        $stmt = $pdo->prepare('
            INSERT INTO organization_department_contacts (department_id, client_id, role, is_primary)
            VALUES (?, ?, "contact", ?)
            ON DUPLICATE KEY UPDATE role = VALUES(role), is_primary = GREATEST(is_primary, VALUES(is_primary))
        ');
        $isPrimary = !empty($_POST['is_primary']) ? 1 : 0;
        if ($isPrimary) {
            $pdo->prepare('UPDATE organization_department_contacts SET is_primary = 0 WHERE department_id = ?')
                ->execute([$departmentId]);
        }
        $stmt->execute([$departmentId, $clientId, $isPrimary]);
        $flag = 'department_contact_added=1';
    } elseif ($action === 'set_primary_contact') {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($departmentId <= 0 || $clientId <= 0) {
            throw new RuntimeException('Invalid department contact');
        }
        $stmt = $pdo->prepare('
            SELECT 1
            FROM organization_department_contacts odc
            JOIN organization_departments od ON od.id = odc.department_id
            WHERE odc.department_id = ? AND odc.client_id = ? AND od.organization_id = ?
            LIMIT 1
        ');
        $stmt->execute([$departmentId, $clientId, $organizationId]);
        if (!$stmt->fetchColumn()) {
            throw new RuntimeException('Department contact not found');
        }
        $pdo->prepare('UPDATE organization_department_contacts SET is_primary = 0 WHERE department_id = ?')
            ->execute([$departmentId]);
        $pdo->prepare('UPDATE organization_department_contacts SET is_primary = 1 WHERE department_id = ? AND client_id = ?')
            ->execute([$departmentId, $clientId]);
        $flag = 'department_contact_primary=1';
    } elseif ($action === 'remove_contact') {
        $departmentId = (int)($_POST['department_id'] ?? 0);
        $clientId = (int)($_POST['client_id'] ?? 0);
        if ($departmentId <= 0 || $clientId <= 0) {
            throw new RuntimeException('Invalid department contact');
        }
        $stmt = $pdo->prepare('
            DELETE odc
            FROM organization_department_contacts odc
            JOIN organization_departments od ON od.id = odc.department_id
            WHERE odc.department_id = ? AND odc.client_id = ? AND od.organization_id = ?
        ');
        $stmt->execute([$departmentId, $clientId, $organizationId]);
        $flag = 'department_contact_removed=1';
    } else {
        throw new RuntimeException('Invalid department action');
    }

    header('Location: ' . $redirect . '&' . $flag);
    exit;
} catch (Throwable $e) {
    @error_log('[organization_departments] ' . $e->getMessage());
    header('Location: ' . $redirect . '&error=' . urlencode($e->getMessage()));
    exit;
}

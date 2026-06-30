<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/invoice_content_links.php';

header('Content-Type: application/json');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!csrf_validate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

try {
    $title = trim((string)($_POST['title'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    $entityType = trim((string)($_POST['entity_type'] ?? ''));
    $entityId = (int)($_POST['entity_id'] ?? 0);
    $linkType = trim((string)($_POST['link_type'] ?? 'manual'));
    $includeOnInvoices = !empty($_POST['include_on_invoices']) ? 1 : 0;
    $resolverMode = trim((string)($_POST['resolver_mode'] ?? 'manual_only'));
    $visibilityScope = trim((string)($_POST['visibility_scope'] ?? 'entity_only'));
    $selectedDepartmentIds = $_POST['selected_department_ids'] ?? [];
    if (!is_array($selectedDepartmentIds)) {
        $selectedDepartmentIds = [];
    }
    $expirationDate = !empty($_POST['expiration_date']) ? (string)$_POST['expiration_date'] : null;

    if ($title === '') {
        throw new Exception('Link title is required');
    }
    if ($url === '') {
        throw new Exception('URL is required');
    }
    if (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
        throw new Exception('Invalid URL format. Links must start with http:// or https://');
    }
    if (!in_array($entityType, ['client', 'organization', 'department', 'project'], true)) {
        throw new Exception('Invalid entity type');
    }
    if ($entityId <= 0) {
        throw new Exception('Invalid entity ID');
    }
    if ($expirationDate !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $expirationDate)) {
        throw new Exception('Invalid expiration date');
    }

    if ($entityType === 'project' && !pa_config_bool($appConfig ?? [], 'project_specific_links_enabled', false)) {
        throw new Exception('Project-specific links are disabled. Enable them in Link Resolver settings before attaching links directly to projects.');
    }

    if ($entityType === 'client') {
        require_record_ownership($pdo, 'clients', $entityId);
    } elseif ($entityType === 'organization') {
        require_record_ownership($pdo, 'organizations', $entityId);
    } elseif ($entityType === 'project') {
        require_record_ownership($pdo, 'projects', $entityId);
    } else {
        $dept = $pdo->prepare('SELECT organization_id FROM organization_departments WHERE id = ?');
        $dept->execute([$entityId]);
        $orgId = (int)($dept->fetchColumn() ?: 0);
        if ($orgId <= 0) {
            throw new Exception('Department not found');
        }
        require_record_ownership($pdo, 'organizations', $orgId);
    }

    $allowedLinkTypes = [
        'manual',
        'manual_dropbox',
        'manual_gdrive',
        'manual_onedrive',
        'manual_webodm_map',
        'manual_webodm_model',
        'manual_external',
        'manual_other',
    ];
    if (!in_array($linkType, $allowedLinkTypes, true)) {
        $linkType = 'manual';
    }
    if (!in_array($resolverMode, ['auto_attach', 'review', 'manual_only', 'excluded'], true)) {
        $resolverMode = 'manual_only';
    }
    if (!in_array($visibilityScope, ['entity_only', 'all_departments', 'selected_departments', 'org_contacts'], true)) {
        $visibilityScope = 'entity_only';
    }
    if ($entityType !== 'organization') {
        $visibilityScope = 'entity_only';
        $selectedDepartmentIds = [];
    }

    $selectedDepartmentIds = array_values(array_unique(array_filter(array_map('intval', $selectedDepartmentIds), static fn($id) => $id > 0)));
    if ($visibilityScope === 'selected_departments') {
        if (!$selectedDepartmentIds) {
            throw new Exception('Select at least one department for selected-department visibility');
        }
        $placeholders = implode(',', array_fill(0, count($selectedDepartmentIds), '?'));
        $deptCheck = $pdo->prepare("SELECT id FROM organization_departments WHERE organization_id = ? AND id IN ({$placeholders})");
        $deptCheck->execute(array_merge([$entityId], $selectedDepartmentIds));
        $validIds = array_map('intval', $deptCheck->fetchAll(PDO::FETCH_COLUMN));
        sort($validIds);
        sort($selectedDepartmentIds);
        if ($validIds !== $selectedDepartmentIds) {
            throw new Exception('One or more selected departments do not belong to this organization');
        }
    } else {
        $selectedDepartmentIds = [];
    }

    $columns = ['entity_type', 'entity_id', 'title', 'url', 'link_type', 'expiration_date', 'is_expired', 'ignore_auto_generation'];
    $values = [$entityType, $entityId, $title, $url, $linkType, $expirationDate, 0, 0];

    if (invoice_content_links_table_has_column($pdo, 'entity_links', 'link_source')) {
        $columns[] = 'link_source';
        $values[] = 'manual';
    }
    if (invoice_content_links_table_has_column($pdo, 'entity_links', 'include_on_invoices')) {
        $columns[] = 'include_on_invoices';
        $values[] = $includeOnInvoices;
    }
    if (invoice_content_links_table_has_column($pdo, 'entity_links', 'resolver_mode')) {
        $columns[] = 'resolver_mode';
        $values[] = $resolverMode;
    }
    if (invoice_content_links_table_has_column($pdo, 'entity_links', 'visibility_scope')) {
        $columns[] = 'visibility_scope';
        $values[] = $visibilityScope;
    }
    if (invoice_content_links_table_has_column($pdo, 'entity_links', 'selected_department_ids')) {
        $columns[] = 'selected_department_ids';
        $values[] = $selectedDepartmentIds ? json_encode($selectedDepartmentIds) : null;
    }
    if (invoice_content_links_table_has_column($pdo, 'entity_links', 'inherited_to_departments')) {
        $columns[] = 'inherited_to_departments';
        $values[] = $visibilityScope === 'all_departments' ? 1 : 0;
    }

    $placeholders = implode(', ', array_fill(0, count($columns), '?'));
    $stmt = $pdo->prepare('INSERT INTO entity_links (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')');
    $stmt->execute($values);

    echo json_encode([
        'success' => true,
        'message' => 'Manual link added successfully',
        'link_id' => (int)$pdo->lastInsertId(),
    ]);
} catch (Throwable $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}

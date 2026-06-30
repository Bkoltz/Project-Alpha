<?php
// src/controllers/organization/organization_add_client.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

$organization_id = (int)($_POST['organization_id'] ?? 0);
$client_id = (int)($_POST['client_id'] ?? 0);

if ($organization_id <= 0 || $client_id <= 0) {
    header('Location: /?page=organization/organizations-list&error=Invalid%20input');
    exit;
}

require_record_ownership($pdo, 'organizations', $organization_id);

$stmt = $pdo->prepare('SELECT id, organization_id, created_by FROM clients WHERE id = ? AND archived = 0 LIMIT 1');
$stmt->execute([$client_id]);
$client = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$client) {
    header('Location: /?page=organization/organization-view&id=' . $organization_id . '&error=Client%20not%20found');
    exit;
}

$currentOrganizationId = isset($client['organization_id']) ? (int)$client['organization_id'] : 0;
$userId = (int)($_SESSION['user']['id'] ?? 0);
$isAdmin = (($_SESSION['user']['role'] ?? '') === 'admin');
$isOrgManager = acl_user_has_org_wide_scope($pdo, $userId, $organization_id);
$createdByUser = isset($client['created_by']) && (int)$client['created_by'] === $userId;

$canAttachClient = $isAdmin
    || $currentOrganizationId === $organization_id
    || ($currentOrganizationId === 0 && ($isOrgManager || $createdByUser));

if (!$canAttachClient) {
    require_once __DIR__ . '/../../utils/acl_middleware.php';
    deny_response('organization/organization-view');
}

// Update client to set organization_id
$stmt = $pdo->prepare('UPDATE clients SET organization_id = ? WHERE id = ?');
$stmt->execute([$organization_id, $client_id]);

header('Location: /?page=organization/organization-view&id=' . $organization_id . '&client_added=1');
exit;

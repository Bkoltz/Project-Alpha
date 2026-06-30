<?php
// src/controllers/financial/vendor_handler.php
// POST controller for vendor CRUD: create, update, deactivate, merge.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

// Auth required
if (empty($_SESSION['user']['id'])) {
    $response['message'] = 'Authentication required';
    echo json_encode($response);
    exit;
}

$userId = (int)$_SESSION['user']['id'];
$orgId  = get_active_org_id();
if ($orgId <= 0 || !user_can($pdo, $userId, 'financial.manage', $orgId)) {
    http_response_code(403);
    $response['message'] = 'Permission denied';
    echo json_encode($response);
    exit;
}

// CSRF required: accept legacy 'csrf' or Symfony '_token'
$csrfOk = false;
$submitted = $_POST['_token'] ?? '';
if (is_string($submitted) && $submitted !== '') {
    $csrfOk = csrf_sf_is_valid('vendor', $submitted);
} else {
    $csrfOk = csrf_validate();
}
if (!$csrfOk) {
    $response['message'] = 'Invalid request (CSRF validation failed)';
    echo json_encode($response);
    exit;
}

$action = $_POST['action'] ?? '';

function jsonResponse(bool $success, string $message, array $extra = []): void {
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function trimNullable(string $value): ?string {
    $value = trim($value);
    return $value === '' ? null : $value;
}

try {
    switch ($action) {
        case 'create':
            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                jsonResponse(false, 'Vendor name is required');
            }

            $email = trimNullable($_POST['email'] ?? '');
            $phone = trimNullable($_POST['phone'] ?? '');
            $website = trimNullable($_POST['website'] ?? '');
            $taxId = trimNullable($_POST['tax_id'] ?? '');
            $defaultCategoryId = isset($_POST['default_category_id']) && $_POST['default_category_id'] !== ''
                ? (int)$_POST['default_category_id']
                : null;
            $notes = trimNullable($_POST['notes'] ?? '');
            $address = trimNullable($_POST['address'] ?? '');

            $stmt = $pdo->prepare('
                INSERT INTO vendors
                    (organization_id, name, email, phone, website, tax_id, default_category_id, notes, address, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
            ');
            $stmt->execute([$orgId, $name, $email, $phone, $website, $taxId, $defaultCategoryId, $notes, $address]);

            $vendorId = (int)$pdo->lastInsertId();
            audit_log($pdo, 'vendor.create', 'vendor', $vendorId, [
                'organization_id' => $orgId,
                'name' => $name,
                'default_category_id' => $defaultCategoryId,
            ]);

            jsonResponse(true, 'Vendor created successfully', ['id' => $vendorId]);

        case 'update':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(false, 'Invalid vendor ID');
            }

            $name = trim($_POST['name'] ?? '');
            if ($name === '') {
                jsonResponse(false, 'Vendor name is required');
            }

            $email = trimNullable($_POST['email'] ?? '');
            $phone = trimNullable($_POST['phone'] ?? '');
            $website = trimNullable($_POST['website'] ?? '');
            $taxId = trimNullable($_POST['tax_id'] ?? '');
            $defaultCategoryId = isset($_POST['default_category_id']) && $_POST['default_category_id'] !== ''
                ? (int)$_POST['default_category_id']
                : null;
            $notes = trimNullable($_POST['notes'] ?? '');
            $address = trimNullable($_POST['address'] ?? '');

            $stmt = $pdo->prepare('
                UPDATE vendors
                SET name = ?, email = ?, phone = ?, website = ?, tax_id = ?,
                    default_category_id = ?, notes = ?, address = ?, updated_at = NOW()
                WHERE id = ? AND organization_id = ? AND is_active = 1
            ');
            $stmt->execute([$name, $email, $phone, $website, $taxId, $defaultCategoryId, $notes, $address, $id, $orgId]);

            if ($stmt->rowCount() === 0) {
                $exists = $pdo->prepare('SELECT 1 FROM vendors WHERE id = ? AND organization_id = ?');
                $exists->execute([$id, $orgId]);
                if (!$exists->fetch()) {
                    jsonResponse(false, 'Vendor not found');
                }
            }

            audit_log($pdo, 'vendor.update', 'vendor', $id, [
                'organization_id' => $orgId,
                'name' => $name,
                'default_category_id' => $defaultCategoryId,
            ]);

            jsonResponse(true, 'Vendor updated successfully', ['id' => $id]);

        case 'deactivate':
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                jsonResponse(false, 'Invalid vendor ID');
            }

            $stmt = $pdo->prepare('
                UPDATE vendors
                SET is_active = 0, updated_at = NOW()
                WHERE id = ? AND organization_id = ? AND is_active = 1
            ');
            $stmt->execute([$id, $orgId]);

            if ($stmt->rowCount() === 0) {
                jsonResponse(false, 'Vendor not found or already inactive');
            }

            audit_log($pdo, 'vendor.deactivate', 'vendor', $id, ['organization_id' => $orgId]);

            jsonResponse(true, 'Vendor deactivated successfully');

        case 'merge':
            $sourceId = (int)($_POST['source_id'] ?? 0);
            $targetId = (int)($_POST['target_id'] ?? 0);

            if ($sourceId <= 0 || $targetId <= 0) {
                jsonResponse(false, 'Source and target vendor IDs are required');
            }
            if ($sourceId === $targetId) {
                jsonResponse(false, 'Source and target vendors must be different');
            }

            // Verify both vendors belong to this organization
            $check = $pdo->prepare('SELECT id, is_active FROM vendors WHERE id IN (?, ?) AND organization_id = ?');
            $check->execute([$sourceId, $targetId, $orgId]);
            $found = $check->fetchAll();
            if (count($found) !== 2) {
                jsonResponse(false, 'One or both vendors not found');
            }
            foreach ($found as $v) {
                if ((int)$v['is_active'] !== 1) {
                    jsonResponse(false, 'Cannot merge inactive vendors');
                }
            }

            // Reassign expenses
            $updateExpenses = $pdo->prepare('UPDATE expenses SET vendor_id = ? WHERE vendor_id = ? AND organization_id = ?');
            $updateExpenses->execute([$targetId, $sourceId, $orgId]);
            $reassigned = (int)$updateExpenses->rowCount();

            // Deactivate source vendor
            $deactivate = $pdo->prepare('
                UPDATE vendors
                SET is_active = 0, updated_at = NOW()
                WHERE id = ? AND organization_id = ?
            ');
            $deactivate->execute([$sourceId, $orgId]);

            audit_log($pdo, 'vendor.merge', 'vendor', $targetId, [
                'organization_id' => $orgId,
                'source_id' => $sourceId,
                'target_id' => $targetId,
                'reassigned_expenses' => $reassigned,
            ]);
            audit_log($pdo, 'vendor.deactivate', 'vendor', $sourceId, [
                'organization_id' => $orgId,
                'reason' => 'merged_into_' . $targetId,
            ]);

            jsonResponse(true, 'Vendors merged successfully', [
                'source_id' => $sourceId,
                'target_id' => $targetId,
                'reassigned_expenses' => $reassigned,
            ]);

        default:
            jsonResponse(false, 'Invalid action');
    }
} catch (Throwable $e) {
    error_log('[vendor_handler] Error: ' . $e->getMessage());
    jsonResponse(false, 'An error occurred while processing the request');
}

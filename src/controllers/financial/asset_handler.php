<?php
// src/controllers/financial/asset_handler.php
// POST controller for financial asset CRUD and lifecycle status updates.

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/csrf_sf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';

header('Content-Type: application/json');

function asset_json_response(bool $success, string $message, array $extra = []): void
{
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra));
    exit;
}

function asset_trim_nullable(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function asset_decimal(string $key, float $default = 0.0): float
{
    $value = $_POST[$key] ?? '';
    if ($value === '' || $value === null) {
        return $default;
    }
    return round((float)$value, 2);
}

function asset_optional_int(string $key): ?int
{
    $value = (int)($_POST[$key] ?? 0);
    return $value > 0 ? $value : null;
}

function asset_assert_org_row(PDO $pdo, string $table, int $id, int $orgId, string $label): void
{
    if ($id <= 0) {
        return;
    }
    $stmt = $pdo->prepare("SELECT 1 FROM {$table} WHERE id = ? AND organization_id = ? LIMIT 1");
    $stmt->execute([$id, $orgId]);
    if (!$stmt->fetchColumn()) {
        throw new RuntimeException($label . ' was not found for the active organization.');
    }
}

$userId = (int)($_SESSION['user']['id'] ?? 0);
if ($userId <= 0) {
    asset_json_response(false, 'Authentication required');
}

$orgId = get_active_org_id();
if ($orgId <= 0 || !user_can($pdo, $userId, 'financial.manage', $orgId)) {
    http_response_code(403);
    asset_json_response(false, 'Permission denied');
}

$submitted = $_POST['_token'] ?? '';
$csrfOk = is_string($submitted) && $submitted !== ''
    ? csrf_sf_is_valid('asset', $submitted)
    : csrf_validate();
if (!$csrfOk) {
    asset_json_response(false, 'Invalid request. Please refresh and try again.');
}

$allowedStatuses = ['planned', 'active', 'maintenance', 'retired', 'sold', 'lost', 'disposed'];
$allowedMethods = ['none', 'straight_line'];
$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid asset ID.');
        }
        $stmt = $pdo->prepare('UPDATE financial_assets SET status = "disposed", disposed_on = COALESCE(disposed_on, CURDATE()) WHERE id = ? AND organization_id = ?');
        $stmt->execute([$id, $orgId]);
        audit_log($pdo, 'asset.dispose', 'financial_asset', $id, ['organization_id' => $orgId]);
        asset_json_response(true, 'Asset marked disposed.', ['redirect' => '/?page=financial/expenses-list&tab=assets&disposed=1']);
    }

    if (!in_array($action, ['create', 'update'], true)) {
        throw new RuntimeException('Invalid action.');
    }

    $id = (int)($_POST['id'] ?? 0);
    if ($action === 'update' && $id <= 0) {
        throw new RuntimeException('Invalid asset ID.');
    }

    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        throw new RuntimeException('Asset name is required.');
    }

    $assetTag = asset_trim_nullable($_POST['asset_tag'] ?? null);
    $assetType = asset_trim_nullable($_POST['asset_type'] ?? null);
    $serialNumber = asset_trim_nullable($_POST['serial_number'] ?? null);
    $location = asset_trim_nullable($_POST['location'] ?? null);
    $notes = asset_trim_nullable($_POST['notes'] ?? null);
    $purchaseDate = asset_trim_nullable($_POST['purchase_date'] ?? null);
    $depreciationStartDate = asset_trim_nullable($_POST['depreciation_start_date'] ?? null);
    $warrantyExpiresOn = asset_trim_nullable($_POST['warranty_expires_on'] ?? null);
    $disposedOn = asset_trim_nullable($_POST['disposed_on'] ?? null);
    $status = (string)($_POST['status'] ?? 'active');
    $method = (string)($_POST['depreciation_method'] ?? 'straight_line');
    $vendorId = asset_optional_int('vendor_id');
    $vendorName = trim((string)($_POST['vendor_name'] ?? ''));
    $categoryId = asset_optional_int('category_id');
    $expenseId = asset_optional_int('expense_id');
    $purchaseCost = max(0.0, asset_decimal('purchase_cost'));
    $salvageValue = max(0.0, asset_decimal('salvage_value'));
    $disposalValue = ($_POST['disposal_value'] ?? '') === '' ? null : max(0.0, asset_decimal('disposal_value'));
    $usefulLifeMonths = asset_optional_int('useful_life_months');

    if (!in_array($status, $allowedStatuses, true)) {
        throw new RuntimeException('Invalid asset status.');
    }
    if (!in_array($method, $allowedMethods, true)) {
        throw new RuntimeException('Invalid depreciation method.');
    }
    if ($method === 'straight_line' && $purchaseCost > 0 && (!$usefulLifeMonths || $usefulLifeMonths <= 0)) {
        throw new RuntimeException('Useful life is required for straight-line depreciation.');
    }
    if ($salvageValue > $purchaseCost && $purchaseCost > 0) {
        throw new RuntimeException('Salvage value cannot be greater than purchase cost.');
    }

    if ($vendorId === null && $vendorName !== '') {
        $vendorLookup = $pdo->prepare('SELECT id FROM vendors WHERE organization_id = ? AND name = ? LIMIT 1');
        $vendorLookup->execute([$orgId, $vendorName]);
        $foundVendorId = (int)$vendorLookup->fetchColumn();
        if ($foundVendorId > 0) {
            $vendorId = $foundVendorId;
        } else {
            $insertVendor = $pdo->prepare('INSERT INTO vendors (organization_id, name) VALUES (?, ?)');
            $insertVendor->execute([$orgId, $vendorName]);
            $vendorId = (int)$pdo->lastInsertId();
            audit_log($pdo, 'vendor.create', 'vendor', $vendorId, ['organization_id' => $orgId, 'name' => $vendorName, 'source' => 'asset']);
        }
    }

    if ($vendorId !== null) {
        asset_assert_org_row($pdo, 'vendors', $vendorId, $orgId, 'Vendor');
    }
    if ($categoryId !== null) {
        asset_assert_org_row($pdo, 'expense_categories', $categoryId, $orgId, 'Category');
    }
    if ($expenseId !== null) {
        asset_assert_org_row($pdo, 'expenses', $expenseId, $orgId, 'Expense');
    }

    if ($action === 'create') {
        $stmt = $pdo->prepare('
            INSERT INTO financial_assets
                (organization_id, vendor_id, category_id, expense_id, asset_tag, name, asset_type, serial_number,
                 status, location, purchase_date, purchase_cost, depreciation_method, depreciation_start_date,
                 useful_life_months, salvage_value, warranty_expires_on, disposed_on, disposal_value, notes, created_by)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $orgId, $vendorId, $categoryId, $expenseId, $assetTag, $name, $assetType, $serialNumber,
            $status, $location, $purchaseDate, $purchaseCost, $method, $depreciationStartDate,
            $usefulLifeMonths, $salvageValue, $warrantyExpiresOn, $disposedOn, $disposalValue, $notes, $userId,
        ]);
        $assetId = (int)$pdo->lastInsertId();
        audit_log($pdo, 'asset.create', 'financial_asset', $assetId, ['organization_id' => $orgId, 'name' => $name]);
        asset_json_response(true, 'Asset created.', ['id' => $assetId, 'redirect' => '/?page=financial/asset-detail&id=' . $assetId . '&created=1']);
    }

    $exists = $pdo->prepare('SELECT 1 FROM financial_assets WHERE id = ? AND organization_id = ?');
    $exists->execute([$id, $orgId]);
    if (!$exists->fetchColumn()) {
        throw new RuntimeException('Asset not found.');
    }

    $stmt = $pdo->prepare('
        UPDATE financial_assets
        SET vendor_id = ?, category_id = ?, expense_id = ?, asset_tag = ?, name = ?, asset_type = ?,
            serial_number = ?, status = ?, location = ?, purchase_date = ?, purchase_cost = ?,
            depreciation_method = ?, depreciation_start_date = ?, useful_life_months = ?, salvage_value = ?,
            warranty_expires_on = ?, disposed_on = ?, disposal_value = ?, notes = ?
        WHERE id = ? AND organization_id = ?
    ');
    $stmt->execute([
        $vendorId, $categoryId, $expenseId, $assetTag, $name, $assetType,
        $serialNumber, $status, $location, $purchaseDate, $purchaseCost,
        $method, $depreciationStartDate, $usefulLifeMonths, $salvageValue,
        $warrantyExpiresOn, $disposedOn, $disposalValue, $notes, $id, $orgId,
    ]);
    audit_log($pdo, 'asset.update', 'financial_asset', $id, ['organization_id' => $orgId, 'name' => $name]);
    asset_json_response(true, 'Asset updated.', ['id' => $id, 'redirect' => '/?page=financial/asset-detail&id=' . $id . '&updated=1']);
} catch (Throwable $e) {
    error_log('[asset_handler] Error: ' . $e->getMessage());
    asset_json_response(false, $e->getMessage());
}

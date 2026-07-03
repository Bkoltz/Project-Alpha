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

function asset_handler_is_ajax(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function asset_redirect_with_message(string $url, string $key, string $message): void
{
    $separator = str_contains($url, '?') ? '&' : '?';
    header('Location: ' . $url . $separator . rawurlencode($key) . '=' . rawurlencode($message));
    exit;
}

function asset_handler_finish(array $response, int $status = 200, string $fallback = '/?page=financial/expenses-list&tab=assets'): void
{
    if (asset_handler_is_ajax()) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    if (!empty($response['success'])) {
        header('Location: ' . (string)($response['redirect'] ?? '/?page=financial/expenses-list&tab=assets'));
        exit;
    }

    asset_redirect_with_message($fallback, 'error', (string)($response['message'] ?? 'Asset request failed'));
}

function asset_json_response(bool $success, string $message, array $extra = []): void
{
    asset_handler_finish(array_merge(['success' => $success, 'message' => $message], $extra), $success ? 200 : 400);
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
    asset_handler_finish(['success' => false, 'message' => 'Authentication required'], 401, '/?page=login');
}

$orgId = active_or_default_org_id($pdo);
if ($orgId <= 0 || !user_can($pdo, $userId, 'financial.manage', $orgId)) {
    asset_handler_finish(['success' => false, 'message' => 'Permission denied'], 403, '/?page=financial/expenses-list&tab=assets');
}

$submitted = $_POST['_token'] ?? '';
$csrfOk = is_string($submitted) && $submitted !== ''
    ? csrf_sf_is_valid('asset', $submitted)
    : csrf_validate();
if (!$csrfOk) {
    asset_handler_finish(['success' => false, 'message' => 'Invalid request. Please refresh and try again.'], 400, '/?page=financial/asset-form');
}

$allowedStatuses = ['planned', 'active', 'maintenance', 'retired', 'sold', 'lost', 'disposed'];
$allowedMethods = ['none', 'straight_line'];
$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'delete') {
        $fallback = '/?page=financial/expenses-list&tab=assets';
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            throw new RuntimeException('Invalid asset ID.');
        }
        [$assetScopeWhere, $assetScopeParams] = finance_scope_clause($pdo, 'a', $userId, $orgId, 'created_by');
        $stmt = $pdo->prepare('UPDATE financial_assets a SET status = "disposed", disposed_on = COALESCE(disposed_on, CURDATE()) WHERE a.id = ? AND ' . $assetScopeWhere);
        $stmt->execute(array_merge([$id], $assetScopeParams));
        audit_log($pdo, 'asset.dispose', 'financial_asset', $id, ['organization_id' => $orgId]);
        asset_json_response(true, 'Asset marked disposed.', ['redirect' => '/?page=financial/expenses-list&tab=assets&disposed=1']);
    }

    if (!in_array($action, ['create', 'update'], true)) {
        throw new RuntimeException('Invalid action.');
    }

    $id = (int)($_POST['id'] ?? 0);
    $fallback = $id > 0 ? '/?page=financial/asset-form&id=' . $id : '/?page=financial/asset-form';
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
        [$expenseScopeWhere, $expenseScopeParams] = finance_scope_clause($pdo, 'e', $userId, $orgId, 'created_by');
        $expenseCheck = $pdo->prepare('SELECT 1 FROM expenses e WHERE e.id = ? AND ' . $expenseScopeWhere . ' LIMIT 1');
        $expenseCheck->execute(array_merge([$expenseId], $expenseScopeParams));
        if (!$expenseCheck->fetchColumn()) {
            throw new RuntimeException('Expense was not found for the active organization.');
        }
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

    [$assetScopeWhere, $assetScopeParams] = finance_scope_clause($pdo, 'a', $userId, $orgId, 'created_by');
    $exists = $pdo->prepare('SELECT 1 FROM financial_assets a WHERE a.id = ? AND ' . $assetScopeWhere);
    $exists->execute(array_merge([$id], $assetScopeParams));
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
    asset_handler_finish(['success' => false, 'message' => $e->getMessage()], 400, $fallback ?? '/?page=financial/asset-form');
}

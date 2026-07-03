<?php
// src/controllers/receipts_handler.php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Suppress any errors before JSON output
error_reporting(E_ERROR | E_PARSE);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/csrf.php';
require_once __DIR__ . '/../utils/acl.php';
require_once __DIR__ . '/../utils/upload_validator.php';

$action = $_POST['action'] ?? null;
$response = ['success' => false, 'message' => ''];

function receipts_handler_is_ajax(): bool
{
    return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');
}

function receipts_handler_redirect_with_message(string $url, string $key, string $message): void
{
    $join = str_contains($url, '?') ? '&' : '?';
    header('Location: ' . $url . $join . rawurlencode($key) . '=' . rawurlencode($message));
    exit;
}

function receipts_handler_finish(array $response, int $status = 200, string $fallback = '/?page=financial/expenses-list&tab=receipts'): void
{
    if (receipts_handler_is_ajax()) {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    if (!empty($response['success'])) {
        header('Location: ' . (string)($response['redirect'] ?? '/?page=financial/expenses-list&tab=receipts'));
        exit;
    }

    receipts_handler_redirect_with_message($fallback, 'error', (string)($response['message'] ?? 'Receipt request failed'));
}

// Validate CSRF token
if (!csrf_validate()) {
    $response['message'] = 'Invalid request (CSRF validation failed)';
    receipts_handler_finish($response, 400, '/?page=financial/receipt-upload');
}

try {
    $userId = (int)($_SESSION['user']['id'] ?? 0);
    $orgId = active_or_default_org_id($pdo);
    if ($userId <= 0 || $orgId <= 0 || !user_can($pdo, $userId, 'financial.manage', $orgId)) {
        $response['message'] = 'Permission denied';
        receipts_handler_finish($response, 403, '/?page=financial/expenses-list&tab=receipts');
    }

    $resolveStoreId = static function (PDO $pdo, int $orgId, string $storeName): ?int {
        $storeName = trim($storeName);
        if ($storeName === '') {
            return null;
        }

        $stmt = $pdo->prepare('INSERT INTO vendors (organization_id, name) VALUES (?, ?) ON DUPLICATE KEY UPDATE name = VALUES(name)');
        $stmt->execute([$orgId, $storeName]);

        $stmt = $pdo->prepare('SELECT id FROM vendors WHERE organization_id = ? AND name = ?');
        $stmt->execute([$orgId, $storeName]);
        $storeId = $stmt->fetchColumn();

        return $storeId ? (int)$storeId : null;
    };

    switch ($action) {
        case 'create':
            // Validate required fields
            $description = trim($_POST['description'] ?? ($_POST['title'] ?? ''));
            $storeName = trim($_POST['store_name'] ?? '');
            $receiptDate = $_POST['receipt_date'] ?? '';
            $amount = $_POST['amount'] ?? '';

            if (empty($description) || empty($receiptDate) || empty($amount)) {
                throw new Exception('Description, date, and amount are required');
            }

            $storeId = $resolveStoreId($pdo, $orgId, $storeName);

            // Handle file upload
            if (!isset($_FILES['receipt_file']) || $_FILES['receipt_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Receipt file is required');
            }

            $file = $_FILES['receipt_file'];
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];

            $uploadErr = validate_upload($file, $allowedTypes, 10 * 1024 * 1024);
            if ($uploadErr !== null) {
                throw new Exception($uploadErr);
            }

            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'receipt_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            
            // Organize by year/month
            $year = date('Y', strtotime($receiptDate));
            $month = date('m', strtotime($receiptDate));
            $baseDir = __DIR__ . '/../uploads/receipts';
            $uploadDir = $baseDir . '/' . $year . '/' . $month;
            
            // Ensure base directory exists
            if (!is_dir($baseDir)) {
                if (!@mkdir($baseDir, 0755, true)) {
                    throw new Exception('Failed to create base receipts directory');
                }
            }
            
            // Ensure base directory is writable
            if (!is_writable($baseDir)) {
                @chmod($baseDir, 0755);
                if (!is_writable($baseDir)) {
                    throw new Exception('Receipts directory is not writable');
                }
            }
            
            // Create year/month directories if they don't exist
            if (!is_dir($uploadDir)) {
                if (!@mkdir($uploadDir, 0755, true)) {
                    throw new Exception('Failed to create upload directory for ' . $year . '/' . $month);
                }
            }
            
            $uploadPath = $uploadDir . '/' . $filename;
            $dbPath = '/src/uploads/receipts/' . $year . '/' . $month . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                throw new Exception('Failed to upload file');
            }

            // Insert into database
            $stmt = $pdo->prepare('
                INSERT INTO receipts (organization_id, store_id, receipt_date, amount, description, file_path, file_name, file_size, mime_type, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$orgId, $storeId, $receiptDate, $amount, $description, $dbPath, $file['name'], $file['size'], $file['type'], $userId]);

            $response['success'] = true;
            $response['message'] = 'Receipt uploaded successfully';
            $response['redirect'] = '/?page=financial/expenses-list&tab=receipts&created=1';
            break;

        case 'delete':
            $receiptId = (int)($_POST['receipt_id'] ?? 0);
            
            if (!$receiptId) {
                throw new Exception('Invalid receipt ID');
            }

            // Get file path before deleting
            [$receiptScopeWhere, $receiptScopeParams] = finance_scope_clause($pdo, 'r', $userId, $orgId, 'uploaded_by');
            $stmt = $pdo->prepare('SELECT r.file_path FROM receipts r WHERE r.id = ? AND ' . $receiptScopeWhere);
            $stmt->execute(array_merge([$receiptId], $receiptScopeParams));
            $receipt = $stmt->fetch();

            if (!$receipt) {
                throw new Exception('Receipt not found');
            }

            // Delete database record
            $stmt = $pdo->prepare('DELETE r FROM receipts r WHERE r.id = ? AND ' . $receiptScopeWhere);
            $stmt->execute(array_merge([$receiptId], $receiptScopeParams));

            // Delete file
            $filePath = __DIR__ . '/../..' . $receipt['file_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            $response['success'] = true;
            $response['message'] = 'Receipt deleted successfully';
            $response['redirect'] = '/?page=financial/expenses-list&tab=receipts&deleted=1';
            break;

        case 'update':
            $receiptId = (int)($_POST['receipt_id'] ?? 0);
            $description = trim($_POST['description'] ?? ($_POST['title'] ?? ''));
            $storeName = trim($_POST['store_name'] ?? '');
            $receiptDate = $_POST['receipt_date'] ?? '';
            $amount = $_POST['amount'] ?? '';

            if (!$receiptId || empty($description) || empty($receiptDate) || empty($amount)) {
                throw new Exception('All fields are required');
            }

            $storeId = $resolveStoreId($pdo, $orgId, $storeName);

            [$receiptScopeWhere, $receiptScopeParams] = finance_scope_clause($pdo, 'r', $userId, $orgId, 'uploaded_by');
            $exists = $pdo->prepare('SELECT 1 FROM receipts r WHERE r.id = ? AND ' . $receiptScopeWhere . ' LIMIT 1');
            $exists->execute(array_merge([$receiptId], $receiptScopeParams));
            if (!$exists->fetchColumn()) {
                throw new Exception('Receipt not found');
            }

            // Update database
            $stmt = $pdo->prepare('
                UPDATE receipts r
                SET description = ?, store_id = ?, receipt_date = ?, amount = ?
                WHERE r.id = ? AND ' . $receiptScopeWhere . '
            ');
            $stmt->execute(array_merge([$description, $storeId, $receiptDate, $amount, $receiptId], $receiptScopeParams));

            // Handle new file upload if provided
            if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['receipt_file'];
                $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];

                $uploadErr = validate_upload($file, $allowedTypes, 10 * 1024 * 1024);
                if ($uploadErr !== null) {
                    throw new Exception($uploadErr);
                }

                // Get old file path
                $stmt = $pdo->prepare('SELECT r.file_path FROM receipts r WHERE r.id = ? AND ' . $receiptScopeWhere);
                $stmt->execute(array_merge([$receiptId], $receiptScopeParams));
                $oldReceipt = $stmt->fetch();

                // Upload new file with year/month structure
                $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
                $filename = 'receipt_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
                
                // Organize by year/month based on new receipt date
                $year = date('Y', strtotime($receiptDate));
                $month = date('m', strtotime($receiptDate));
                $baseDir = __DIR__ . '/../uploads/receipts';
                $uploadDir = $baseDir . '/' . $year . '/' . $month;
                
                // Ensure base directory exists
                if (!is_dir($baseDir)) {
                    if (!@mkdir($baseDir, 0755, true)) {
                        throw new Exception('Failed to create base receipts directory');
                    }
                }
                
                // Ensure base directory is writable
                if (!is_writable($baseDir)) {
                    @chmod($baseDir, 0755);
                    if (!is_writable($baseDir)) {
                        throw new Exception('Receipts directory is not writable');
                    }
                }
                
                // Create year/month directories if they don't exist
                if (!is_dir($uploadDir)) {
                    if (!@mkdir($uploadDir, 0755, true)) {
                        throw new Exception('Failed to create upload directory for ' . $year . '/' . $month);
                    }
                }
                
                $uploadPath = $uploadDir . '/' . $filename;
                $dbPath = '/src/uploads/receipts/' . $year . '/' . $month . '/' . $filename;

                if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                    throw new Exception('Failed to upload new file');
                }

                // Update file path in database
                $stmt = $pdo->prepare('UPDATE receipts r SET file_path = ?, file_name = ?, file_size = ?, mime_type = ?, uploaded_by = ? WHERE r.id = ? AND ' . $receiptScopeWhere);
                $stmt->execute(array_merge([$dbPath, $file['name'], $file['size'], $file['type'], $userId, $receiptId], $receiptScopeParams));

                // Delete old file
                if ($oldReceipt) {
                    $oldFilePath = __DIR__ . '/../..' . $oldReceipt['file_path'];
                    if (file_exists($oldFilePath)) {
                        @unlink($oldFilePath);
                    }
                }
            }

            $response['success'] = true;
            $response['message'] = 'Receipt updated successfully';
            $response['redirect'] = '/?page=financial/receipt-detail&id=' . $receiptId;
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Throwable $e) {
    $response['message'] = $e->getMessage();
    error_log('[receipts_handler] Error: ' . $e->getMessage());
}

receipts_handler_finish($response, !empty($response['success']) ? 200 : 400);

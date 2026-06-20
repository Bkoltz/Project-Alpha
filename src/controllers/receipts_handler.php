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

$action = $_POST['action'] ?? null;
$response = ['success' => false, 'message' => ''];

// Validate CSRF token
if (!csrf_validate()) {
    $response['message'] = 'Invalid request (CSRF validation failed)';
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

try {
    // Get current org_id (default to 1 for now, should come from session/user context)
    $orgId = 1;
    $userId = $_SESSION['user_id'] ?? null;
    
    // Ensure we have a valid user ID or NULL
    if ($userId === null) {
        // Get first admin user if exists
        $stmt = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
        $adminUser = $stmt->fetch();
        if ($adminUser) {
            $userId = $adminUser['id'];
        }
        // Otherwise $userId remains NULL which is acceptable for uploaded_by
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

            require_once __DIR__ . '/../utils/upload_validator.php';
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
            $response['redirect'] = '/?page=financial/receipts-list';
            break;

        case 'delete':
            $receiptId = (int)($_POST['receipt_id'] ?? 0);
            
            if (!$receiptId) {
                throw new Exception('Invalid receipt ID');
            }

            // Get file path before deleting
            $stmt = $pdo->prepare('SELECT file_path FROM receipts WHERE id = ? AND organization_id = ?');
            $stmt->execute([$receiptId, $orgId]);
            $receipt = $stmt->fetch();

            if (!$receipt) {
                throw new Exception('Receipt not found');
            }

            // Delete database record
            $stmt = $pdo->prepare('DELETE FROM receipts WHERE id = ? AND organization_id = ?');
            $stmt->execute([$receiptId, $orgId]);

            // Delete file
            $filePath = __DIR__ . '/../..' . $receipt['file_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            $response['success'] = true;
            $response['message'] = 'Receipt deleted successfully';
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

            // Update database
            $stmt = $pdo->prepare('
                UPDATE receipts 
                SET description = ?, store_id = ?, receipt_date = ?, amount = ?
                WHERE id = ? AND organization_id = ?
            ');
            $stmt->execute([$description, $storeId, $receiptDate, $amount, $receiptId, $orgId]);

            // Handle new file upload if provided
            if (isset($_FILES['receipt_file']) && $_FILES['receipt_file']['error'] === UPLOAD_ERR_OK) {
                $file = $_FILES['receipt_file'];
                $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];

                $uploadErr = validate_upload($file, $allowedTypes, 10 * 1024 * 1024);
                if ($uploadErr !== null) {
                    throw new Exception($uploadErr);
                }

                // Get old file path
                $stmt = $pdo->prepare('SELECT file_path FROM receipts WHERE id = ? AND organization_id = ?');
                $stmt->execute([$receiptId, $orgId]);
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
                $stmt = $pdo->prepare('UPDATE receipts SET file_path = ?, file_name = ?, file_size = ?, mime_type = ?, uploaded_by = ? WHERE id = ? AND organization_id = ?');
                $stmt->execute([$dbPath, $file['name'], $file['size'], $file['type'], $userId, $receiptId, $orgId]);

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

header('Content-Type: application/json');
echo json_encode($response);

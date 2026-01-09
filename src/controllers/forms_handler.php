<?php
// src/controllers/forms_handler.php
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
    $userId = $_SESSION['user_id'] ?? 1;

    switch ($action) {
        case 'create_category':
            // Create new form category
            $title = trim($_POST['title'] ?? '');

            if (empty($title)) {
                throw new Exception('Category title is required');
            }

            $stmt = $pdo->prepare('
                INSERT INTO form_categories (org_id, title, created_by)
                VALUES (?, ?, ?)
            ');
            $stmt->execute([$orgId, $title, !empty($userId) ? $userId : null]);
            $categoryId = $pdo->lastInsertId();

            $response['success'] = true;
            $response['message'] = 'Category created successfully';
            $response['category_id'] = $categoryId;
            $response['redirect'] = '/?page=financial/forms-list';
            break;

        case 'update_category':
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $title = trim($_POST['title'] ?? '');

            if (!$categoryId || empty($title)) {
                throw new Exception('Category ID and title are required');
            }

            $stmt = $pdo->prepare('
                UPDATE form_categories 
                SET title = ?
                WHERE id = ? AND org_id = ?
            ');
            $stmt->execute([$title, $categoryId, $orgId]);

            $response['success'] = true;
            $response['message'] = 'Category updated successfully';
            break;

        case 'delete_category':
            $categoryId = (int)($_POST['category_id'] ?? 0);

            if (!$categoryId) {
                throw new Exception('Invalid category ID');
            }

            // Get all documents in this category to delete files
            $stmt = $pdo->prepare('SELECT file_path FROM form_documents WHERE category_id = ?');
            $stmt->execute([$categoryId]);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Delete category (will cascade delete documents due to FK)
            $stmt = $pdo->prepare('
                DELETE FROM form_categories 
                WHERE id = ? AND org_id = ?
            ');
            $stmt->execute([$categoryId, $orgId]);

            // Delete files
            foreach ($documents as $doc) {
                $filePath = __DIR__ . '/../../' . ltrim($doc['file_path'], '/');
                if (file_exists($filePath)) {
                    @unlink($filePath);
                }
            }

            $response['success'] = true;
            $response['message'] = 'Category and all documents deleted successfully';
            break;

        case 'upload_document':
            $categoryId = (int)($_POST['category_id'] ?? 0);

            if (!$categoryId) {
                throw new Exception('Category ID is required');
            }

            // Verify category exists and belongs to org
            $stmt = $pdo->prepare('SELECT id FROM form_categories WHERE id = ? AND org_id = ?');
            $stmt->execute([$categoryId, $orgId]);
            if (!$stmt->fetch()) {
                throw new Exception('Category not found');
            }

            // Handle file upload
            if (!isset($_FILES['document_file']) || $_FILES['document_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Document file is required');
            }

            $file = $_FILES['document_file'];
            $allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'application/pdf'];
            
            if (!in_array($file['type'], $allowedTypes)) {
                throw new Exception('Invalid file type. Only JPEG, PNG, GIF, and PDF files are allowed');
            }

            // Check file size (20MB max)
            if ($file['size'] > 20 * 1024 * 1024) {
                throw new Exception('File size must be less than 20MB');
            }

            // Get category title for directory structure
            $stmt = $pdo->prepare('SELECT title FROM form_categories WHERE id = ? AND org_id = ?');
            $stmt->execute([$categoryId, $orgId]);
            $category = $stmt->fetch();
            
            if (!$category) {
                throw new Exception('Category not found');
            }
            
            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'form_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            
            // Organize by category
            $categoryDir = preg_replace('/[^a-z0-9-_]/i', '_', $category['title']);
            $baseDir = __DIR__ . '/../../uploads/forms';
            $uploadDir = $baseDir . '/' . $categoryDir;
            
            // Ensure base directory exists
            if (!is_dir($baseDir)) {
                if (!@mkdir($baseDir, 0755, true)) {
                    throw new Exception('Failed to create base forms directory');
                }
            }
            
            // Ensure base directory is writable
            if (!is_writable($baseDir)) {
                @chmod($baseDir, 0755);
                if (!is_writable($baseDir)) {
                    throw new Exception('Forms directory is not writable');
                }
            }
            
            // Create category directory if it doesn't exist
            if (!is_dir($uploadDir)) {
                if (!@mkdir($uploadDir, 0755, true)) {
                    throw new Exception('Failed to create upload directory for category: ' . $categoryDir);
                }
            }
            
            $uploadPath = $uploadDir . '/' . $filename;
            $dbPath = '/uploads/forms/' . $categoryDir . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                throw new Exception('Failed to upload file');
            }

            // Delete old document if exists
            $stmt = $pdo->prepare('SELECT file_path FROM form_documents WHERE category_id = ?');
            $stmt->execute([$categoryId]);
            $oldDoc = $stmt->fetch();
            
            if ($oldDoc) {
                // Delete old file
                $oldFilePath = __DIR__ . '/../../' . ltrim($oldDoc['file_path'], '/');
                if (file_exists($oldFilePath)) {
                    @unlink($oldFilePath);
                }
                
                // Update existing document
                $stmt = $pdo->prepare('
                    UPDATE form_documents 
                    SET file_path = ?, file_name = ?, file_size = ?, mime_type = ?, uploaded_by = ?, uploaded_at = NOW()
                    WHERE category_id = ?
                ');
                $stmt->execute([$dbPath, $file['name'], $file['size'], $file['type'], $userId, $categoryId]);
            } else {
                // Insert new document
                $stmt = $pdo->prepare('
                    INSERT INTO form_documents (category_id, file_path, file_name, file_size, mime_type, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$categoryId, $dbPath, $file['name'], $file['size'], $file['type'], $userId]);
            }

            $response['success'] = true;
            $response['message'] = 'Document uploaded successfully';
            $response['redirect'] = '/?page=financial/form-detail&id=' . $categoryId;
            break;

        case 'delete_document':
            $documentId = (int)($_POST['document_id'] ?? 0);

            if (!$documentId) {
                throw new Exception('Invalid document ID');
            }

            // Get document details
            $stmt = $pdo->prepare('
                SELECT fd.file_path, fc.org_id, fd.category_id
                FROM form_documents fd
                JOIN form_categories fc ON fd.category_id = fc.id
                WHERE fd.id = ?
            ');
            $stmt->execute([$documentId]);
            $doc = $stmt->fetch();

            if (!$doc || $doc['org_id'] != $orgId) {
                throw new Exception('Document not found');
            }

            // Delete database record
            $stmt = $pdo->prepare('DELETE FROM form_documents WHERE id = ?');
            $stmt->execute([$documentId]);

            // Delete file
            $filePath = __DIR__ . '/../../' . ltrim($doc['file_path'], '/');
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            $response['success'] = true;
            $response['message'] = 'Document deleted successfully';
            $response['redirect'] = '/?page=financial/forms-list';
            break;

        case 'email_form':
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $recipientType = $_POST['recipient_type'] ?? ''; // 'client' or 'organization'
            $recipientId = (int)($_POST['recipient_id'] ?? 0);

            if (!$categoryId || !$recipientType || !$recipientId) {
                throw new Exception('Missing required parameters');
            }

            // Get form details
            $stmt = $pdo->prepare('
                SELECT fc.title, fd.file_path, fd.file_name
                FROM form_categories fc
                LEFT JOIN form_documents fd ON fc.id = fd.category_id
                WHERE fc.id = ? AND fc.org_id = ?
            ');
            $stmt->execute([$categoryId, $orgId]);
            $form = $stmt->fetch();

            if (!$form || !$form['file_path']) {
                throw new Exception('Form not found or no document uploaded');
            }

            // Get recipient email(s)
            $emails = [];
            if ($recipientType === 'client') {
                $stmt = $pdo->prepare('SELECT email, name FROM clients WHERE id = ?');
                $stmt->execute([$recipientId]);
                $client = $stmt->fetch();
                if ($client && $client['email']) {
                    $emails[] = ['email' => $client['email'], 'name' => $client['name']];
                }
            } elseif ($recipientType === 'organization') {
                $stmt = $pdo->prepare('
                    SELECT c.email, c.name 
                    FROM clients c 
                    WHERE c.organization_id = ? AND c.email IS NOT NULL AND c.email != ""
                ');
                $stmt->execute([$recipientId]);
                $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($emails)) {
                throw new Exception('No email addresses found for selected recipient');
            }

            // Send emails
            require_once __DIR__ . '/../config/app.php';
            $brandName = $appConfig['brand_name'] ?? 'Project Alpha';
            $fromEmail = $appConfig['from_email'] ?? 'noreply@localhost';
            
            $subject = $brandName . ' - ' . $form['title'];
            $filePath = __DIR__ . '/../../' . ltrim($form['file_path'], '/');
            
            $successCount = 0;
            foreach ($emails as $recipient) {
                $body = "Hello " . $recipient['name'] . ",\n\n";
                $body .= "Please find attached: " . $form['title'] . "\n\n";
                $body .= "Best regards,\n" . $brandName;

                // Simple email (in production, use PHPMailer or similar for attachments)
                $headers = "From: " . $fromEmail . "\r\n";
                $headers .= "Reply-To: " . $fromEmail . "\r\n";
                
                // Note: Basic mail() doesn't support attachments well
                // This is a placeholder - integrate with proper email service
                if (@mail($recipient['email'], $subject, $body, $headers)) {
                    $successCount++;
                }
            }

            if ($successCount > 0) {
                $response['success'] = true;
                $response['message'] = 'Form emailed to ' . $successCount . ' recipient(s)';
            } else {
                throw new Exception('Failed to send emails. Please configure email service.');
            }
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Throwable $e) {
    $response['message'] = $e->getMessage();
    error_log('[forms_handler] Error: ' . $e->getMessage());
}

header('Content-Type: application/json');
echo json_encode($response);

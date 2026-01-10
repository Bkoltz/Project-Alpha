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
    $userId = $_SESSION['user_id'] ?? null;
    
    // Ensure we have a valid user ID
    if ($userId === null) {
        // Get first admin user or create a default one if none exists
        $stmt = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1");
        $adminUser = $stmt->fetch();
        if ($adminUser) {
            $userId = $adminUser['id'];
        } else {
            // No admin user exists, use NULL for created_by
            $userId = null;
        }
    }

    switch ($action) {
        case 'quick_upload':
            // Quick upload - create category and upload file in one action
            $title = trim($_POST['title'] ?? '');

            if (empty($title)) {
                throw new Exception('File name is required');
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

            // Create category for this file (type='file')
            $stmt = $pdo->prepare('
                INSERT INTO form_categories (org_id, title, type, created_by)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$orgId, $title, 'file', $userId]);
            $categoryId = $pdo->lastInsertId();

            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'form_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            
            // Organize by category
            $categoryDir = preg_replace('/[^a-z0-9-_]/i', '_', $title);
            $baseDir = __DIR__ . '/../uploads/forms';
            $uploadDir = $baseDir . '/' . $categoryDir;
            
            // Ensure directories exist
            if (!is_dir($baseDir) && !@mkdir($baseDir, 0755, true)) {
                throw new Exception('Failed to create base forms directory');
            }
            if (!is_dir($uploadDir) && !@mkdir($uploadDir, 0755, true)) {
                throw new Exception('Failed to create upload directory');
            }
            
            $uploadPath = $uploadDir . '/' . $filename;
            $dbPath = '/src/uploads/forms/' . $categoryDir . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                throw new Exception('Failed to upload file');
            }

            // Insert document
            $stmt = $pdo->prepare('
                INSERT INTO form_documents (category_id, file_path, file_name, file_size, mime_type, uploaded_by)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$categoryId, $dbPath, $file['name'], $file['size'], $file['type'], $userId]);

            $response['success'] = true;
            $response['message'] = 'File uploaded successfully';
            $response['redirect'] = '/?page=financial/form-detail&id=' . $categoryId;
            break;

        case 'create_category':
            // Create new form folder (type='folder')
            $title = trim($_POST['title'] ?? '');

            if (empty($title)) {
                throw new Exception('Folder name is required');
            }

            $stmt = $pdo->prepare('
                INSERT INTO form_categories (org_id, title, type, created_by)
                VALUES (?, ?, ?, ?)
            ');
            $stmt->execute([$orgId, $title, 'folder', $userId]);
            $categoryId = $pdo->lastInsertId();

            $response['success'] = true;
            $response['message'] = 'Folder created successfully';
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
                $filePath = __DIR__ . '/../..' . $doc['file_path'];
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

            // Verify category exists and belongs to org, get its type
            $stmt = $pdo->prepare('SELECT id, type, title FROM form_categories WHERE id = ? AND org_id = ?');
            $stmt->execute([$categoryId, $orgId]);
            $category = $stmt->fetch();
            if (!$category) {
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

            
            // Generate unique filename
            $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
            $filename = 'form_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
            
            // Organize by category
            $categoryDir = preg_replace('/[^a-z0-9-_]/i', '_', $category['title']);
            $baseDir = __DIR__ . '/../uploads/forms';
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
            $dbPath = '/src/uploads/forms/' . $categoryDir . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $uploadPath)) {
                throw new Exception('Failed to upload file');
            }

            // Handle based on category type
            if ($category['type'] === 'file') {
                // For single file categories, replace existing document
                $stmt = $pdo->prepare('SELECT file_path FROM form_documents WHERE category_id = ?');
                $stmt->execute([$categoryId]);
                $oldDoc = $stmt->fetch();
                
                if ($oldDoc) {
                    // Delete old file
                    $oldFilePath = __DIR__ . '/../..' . $oldDoc['file_path'];
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
            } else {
                // For folder categories, always add new document (allow multiple)
                $stmt = $pdo->prepare('
                    INSERT INTO form_documents (category_id, file_path, file_name, file_size, mime_type, uploaded_by)
                    VALUES (?, ?, ?, ?, ?, ?)
                ');
                $stmt->execute([$categoryId, $dbPath, $file['name'], $file['size'], $file['type'], $userId]);
            }

            $response['success'] = true;
            $response['message'] = 'Document uploaded successfully';
            // Route to appropriate detail page based on type
            if ($category['type'] === 'folder') {
                $response['redirect'] = '/?page=financial/folder-detail&id=' . $categoryId;
            } else {
                $response['redirect'] = '/?page=financial/form-detail&id=' . $categoryId;
            }
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
            $filePath = __DIR__ . '/../..' . $doc['file_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }

            // Get category type to determine redirect
            $stmt = $pdo->prepare('SELECT type FROM form_categories WHERE id = ?');
            $stmt->execute([$doc['category_id']]);
            $catType = $stmt->fetchColumn();

            $response['success'] = true;
            $response['message'] = 'Document deleted successfully';
            // Redirect back to folder view if it's a folder, otherwise forms list
            if ($catType === 'folder') {
                $response['redirect'] = '/?page=financial/folder-detail&id=' . $doc['category_id'];
            } else {
                $response['redirect'] = '/?page=financial/forms-list';
            }
            break;

        case 'email_form':
            $categoryId = (int)($_POST['category_id'] ?? 0);
            $recipientType = $_POST['recipient_type'] ?? ''; // 'client', 'clients', or 'organization'
            $recipientId = (int)($_POST['recipient_id'] ?? 0);
            $clientIds = $_POST['client_ids'] ?? [];

            if (!$categoryId || !$recipientType) {
                throw new Exception('Missing required parameters');
            }
            
            if ($recipientType !== 'clients' && !$recipientId) {
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
            } elseif ($recipientType === 'clients') {
                if (empty($clientIds) || !is_array($clientIds)) {
                    throw new Exception('At least one client must be selected');
                }
                $placeholders = str_repeat('?,', count($clientIds) - 1) . '?';
                $stmt = $pdo->prepare("
                    SELECT email, name FROM clients 
                    WHERE id IN ($placeholders) AND email IS NOT NULL AND email != ''
                ");
                $stmt->execute($clientIds);
                $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

        case 'email_bulk_documents':
            $folderId = (int)($_POST['folder_id'] ?? 0);
            $documentIdsJson = $_POST['document_ids'] ?? '';
            $recipientType = $_POST['recipient_type'] ?? ''; // 'client', 'clients', or 'organization'
            $recipientId = (int)($_POST['recipient_id'] ?? 0);
            $clientIds = $_POST['client_ids'] ?? [];

            if (!$folderId || !$documentIdsJson || !$recipientType) {
                throw new Exception('Missing required parameters');
            }

            $documentIds = json_decode($documentIdsJson, true);
            if (!is_array($documentIds) || empty($documentIds)) {
                throw new Exception('Invalid or empty document list');
            }

            // Get folder name
            $stmt = $pdo->prepare('
                SELECT title FROM form_categories 
                WHERE id = ? AND org_id = ? AND type = "folder"
            ');
            $stmt->execute([$folderId, $orgId]);
            $folder = $stmt->fetch();
            if (!$folder) {
                throw new Exception('Folder not found');
            }

            // Get documents
            $placeholders = str_repeat('?,', count($documentIds) - 1) . '?';
            $stmt = $pdo->prepare("
                SELECT fd.id, fd.file_path, fd.file_name
                FROM form_documents fd
                WHERE fd.id IN ($placeholders) AND fd.category_id = ?
            ");
            $stmt->execute([...$documentIds, $folderId]);
            $documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($documents)) {
                throw new Exception('No valid documents found');
            }

            // Get recipient email(s)
            $emails = [];
            if ($recipientType === 'client') {
                if (!$recipientId) {
                    throw new Exception('Client ID is required');
                }
                $stmt = $pdo->prepare('SELECT email, name FROM clients WHERE id = ?');
                $stmt->execute([$recipientId]);
                $client = $stmt->fetch();
                if ($client && $client['email']) {
                    $emails[] = ['email' => $client['email'], 'name' => $client['name']];
                }
            } elseif ($recipientType === 'clients') {
                if (empty($clientIds) || !is_array($clientIds)) {
                    throw new Exception('At least one client must be selected');
                }
                $placeholders = str_repeat('?,', count($clientIds) - 1) . '?';
                $stmt = $pdo->prepare("
                    SELECT email, name FROM clients 
                    WHERE id IN ($placeholders) AND email IS NOT NULL AND email != ''
                ");
                $stmt->execute($clientIds);
                $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } elseif ($recipientType === 'organization') {
                if (!$recipientId) {
                    throw new Exception('Organization ID is required');
                }
                $stmt = $pdo->prepare('
                    SELECT c.email, c.name 
                    FROM clients c 
                    WHERE c.organization_id = ? AND c.email IS NOT NULL AND c.email != ""
                ');
                $stmt->execute([$recipientId]);
                $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }

            if (empty($emails)) {
                throw new Exception('No email addresses found for selected recipient(s)');
            }

            // Send emails
            require_once __DIR__ . '/../config/app.php';
            $brandName = $appConfig['brand_name'] ?? 'Project Alpha';
            $fromEmail = $appConfig['from_email'] ?? 'noreply@localhost';
            
            $subject = $brandName . ' - ' . $folder['title'] . ' Documents';
            
            $successCount = 0;
            foreach ($emails as $recipient) {
                $body = "Hello " . $recipient['name'] . ",\n\n";
                $body .= "Please find the following documents from " . $folder['title'] . ":\n\n";
                
                foreach ($documents as $doc) {
                    $body .= "- " . $doc['file_name'] . "\n";
                }
                
                $body .= "\nBest regards,\n" . $brandName;

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
                $response['message'] = 'Documents emailed to ' . $successCount . ' recipient(s)';
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

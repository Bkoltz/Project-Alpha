<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/worker_documents.php';
require_once __DIR__ . '/../../utils/upload_validator.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit;
}

$actorId = (int)($_SESSION['user']['id'] ?? 0);
if ($actorId <= 0 || !user_can($pdo, $actorId, 'users.manage')) {
    http_response_code(403);
    exit('You do not have permission to manage worker documents.');
}

$userId = max(0, (int)($_POST['user_id'] ?? 0));
$workerProfileId = max(0, (int)($_POST['worker_profile_id'] ?? 0));
$action = (string)($_POST['action'] ?? 'upload');

function worker_document_redirect(int $userId, int $workerProfileId, string $key, string $message): never
{
    if ($userId > 0 && empty($_POST['return_tab'])) {
        header('Location: /?page=account-edit&id=' . $userId . '&' . $key . '=' . rawurlencode($message) . '#personnel-documents');
    } else {
        header('Location: /?page=settings&tab=business-units&worker_profile_id=' . $workerProfileId . '&' . $key . '=' . rawurlencode($message) . '#worker-documents');
    }
    exit;
}

function worker_document_date(string $value, string $label): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value) {
        throw new InvalidArgumentException($label . ' must be a valid date.');
    }
    return $value;
}

try {
    if ($workerProfileId > 0) {
        $subjectStatement = $pdo->prepare(
            "SELECT wp.id worker_profile_id,wp.user_id,wp.display_name,u.email,u.username,ep.first_name,ep.last_name
             FROM worker_profiles wp LEFT JOIN users u ON u.id=wp.user_id AND u.deleted_at IS NULL
             LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE wp.id=? LIMIT 1"
        );
        $subjectStatement->execute([$workerProfileId]);
        $subject = $subjectStatement->fetch(PDO::FETCH_ASSOC);
        if ($subject) $userId = (int)($subject['user_id'] ?? 0);
    } elseif ($userId > 0) {
        $subjectStatement = $pdo->prepare(
            "SELECT wp.id worker_profile_id,u.id user_id,u.email,u.username,ep.first_name,ep.last_name,
                    COALESCE(NULLIF(wp.display_name,''),NULLIF(TRIM(CONCAT_WS(' ',ep.first_name,ep.last_name)),''),NULLIF(u.username,''),u.email) display_name
             FROM users u LEFT JOIN worker_profiles wp ON wp.user_id=u.id
             LEFT JOIN employee_profiles ep ON ep.user_id=u.id
             WHERE u.id=? AND u.deleted_at IS NULL LIMIT 1"
        );
        $subjectStatement->execute([$userId]);
        $subject = $subjectStatement->fetch(PDO::FETCH_ASSOC);
        if ($subject) $workerProfileId = (int)($subject['worker_profile_id'] ?? 0);
    } else {
        $subject = false;
    }
    if (!$subject) {
        throw new RuntimeException('Worker or user not found.');
    }

    if (in_array($action, ['archive', 'restore'], true)) {
        $documentId = max(0, (int)($_POST['document_id'] ?? 0));
        if ($documentId <= 0) {
            throw new InvalidArgumentException('Choose a valid worker document.');
        }
        $documentStatement = $pdo->prepare('SELECT id,status FROM worker_documents WHERE id=? AND (worker_profile_id=? OR (worker_profile_id IS NULL AND user_id=?)) LIMIT 1');
        $documentStatement->execute([$documentId, $workerProfileId ?: -1, $userId ?: -1]);
        if (!$documentStatement->fetch(PDO::FETCH_ASSOC)) {
            throw new RuntimeException('Worker document not found.');
        }
        if ($action === 'archive') {
            $pdo->prepare("UPDATE worker_documents SET status='archived',archived_by=?,archived_at=NOW() WHERE id=?")
                ->execute([$actorId, $documentId]);
            audit_log($pdo, 'worker_document.archived', 'worker_document', $documentId, ['worker_profile_id' => $workerProfileId, 'worker_user_id' => $userId ?: null]);
            worker_document_redirect($userId, $workerProfileId, 'document_msg', 'Worker document archived. The signed file was retained.');
        }
        $pdo->prepare("UPDATE worker_documents SET status='current',archived_by=NULL,archived_at=NULL WHERE id=?")
            ->execute([$documentId]);
        audit_log($pdo, 'worker_document.restored', 'worker_document', $documentId, ['worker_profile_id' => $workerProfileId, 'worker_user_id' => $userId ?: null]);
        worker_document_redirect($userId, $workerProfileId, 'document_msg', 'Worker document restored.');
    }

    if ($action !== 'upload') {
        throw new InvalidArgumentException('Unsupported worker-document action.');
    }

    $title = trim((string)($_POST['title'] ?? ''));
    $category = (string)($_POST['category'] ?? 'other');
    $categories = worker_document_category_labels();
    $notes = trim((string)($_POST['notes'] ?? ''));
    $signedOn = worker_document_date((string)($_POST['signed_on'] ?? ''), 'Signed date');
    $expiresOn = worker_document_date((string)($_POST['expires_on'] ?? ''), 'Expiration date');
    if ($title === '' || mb_strlen($title) > 255) {
        throw new InvalidArgumentException('Enter a document title up to 255 characters.');
    }
    if (!isset($categories[$category])) {
        $category = 'other';
    }
    if (mb_strlen($notes) > 5000) {
        throw new InvalidArgumentException('Notes must be 5,000 characters or fewer.');
    }
    if ($signedOn !== null && $expiresOn !== null && $expiresOn < $signedOn) {
        throw new InvalidArgumentException('Expiration date cannot be before the signed date.');
    }
    if (!isset($_FILES['worker_document'])) {
        throw new InvalidArgumentException('Choose a worker document to upload.');
    }

    $targetDirectory = $workerProfileId > 0 ? worker_documents_profile_directory($workerProfileId) : worker_documents_user_directory($userId);
    $allowedTypes = [
        'application/pdf' => 'pdf',
        'image/jpeg' => ['jpg', 'jpeg'],
        'image/png' => 'png',
        'application/msword' => 'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    $uploadError = null;
    $storedName = validate_and_store_upload(
        $_FILES['worker_document'],
        $allowedTypes,
        15 * 1024 * 1024,
        $targetDirectory,
        $uploadError,
        ['require_pdf_header' => true, 'reject_pdf_active_content' => true]
    );
    if ($storedName === null) {
        throw new RuntimeException($uploadError ?: 'The worker document could not be saved.');
    }

    $absolutePath = $targetDirectory . DIRECTORY_SEPARATOR . $storedName;
    $dbPath = $workerProfileId > 0 ? worker_document_profile_db_path($workerProfileId, $storedName) : worker_document_db_path($userId, $storedName);
    $originalName = basename((string)($_FILES['worker_document']['name'] ?? $title));
    $displayName = trim((string)($subject['display_name'] ?? '')) ?: trim((string)($subject['first_name'] ?? '') . ' ' . (string)($subject['last_name'] ?? ''));
    if ($displayName === '') {
        $displayName = trim((string)($subject['username'] ?? '')) ?: (string)($subject['email'] ?? 'Worker');
    }
    $contentHash = hash_file('sha256', $absolutePath);

    try {
        $statement = $pdo->prepare(
            'INSERT INTO worker_documents
                (user_id,worker_profile_id,worker_name_snapshot,worker_email_snapshot,category,title,notes,signed_on,expires_on,
                 worker_visible,original_name,stored_name,file_path,mime_type,file_size,content_sha256,uploaded_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
        );
        $statement->execute([
            $userId ?: null, $workerProfileId ?: null, $displayName, trim((string)($subject['email'] ?? '')) ?: null, $category, $title, $notes ?: null,
            $signedOn, $expiresOn, !empty($_POST['worker_visible']) ? 1 : 0,
            $originalName, $storedName, $dbPath, worker_document_detect_mime($absolutePath),
            (int)filesize($absolutePath), $contentHash, $actorId,
        ]);
        $documentId = (int)$pdo->lastInsertId();
    } catch (Throwable $error) {
        @unlink($absolutePath);
        throw $error;
    }
    audit_log($pdo, 'worker_document.uploaded', 'worker_document', $documentId, [
        'worker_profile_id' => $workerProfileId ?: null,
        'worker_user_id' => $userId ?: null,
        'category' => $category,
        'expires_on' => $expiresOn,
        'content_sha256' => $contentHash,
    ]);
    worker_document_redirect($userId, $workerProfileId, 'document_msg', 'Worker document uploaded.');
} catch (Throwable $error) {
    @error_log('[worker-documents] ' . $error->getMessage());
    worker_document_redirect($userId, $workerProfileId, 'document_error', $error->getMessage());
}

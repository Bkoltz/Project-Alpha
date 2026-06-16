<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';

header('Content-Type: application/json');

// Require authenticated session
if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

// CSRF check (JSON-friendly)
if (!csrf_validate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

try {
    // Validate required fields
    $title = trim((string)($_POST['title'] ?? ''));
    $url = trim((string)($_POST['url'] ?? ''));
    $entityType = $_POST['entity_type'] ?? '';
    $entityId = (int)($_POST['entity_id'] ?? 0);
    $expirationDate = !empty($_POST['expiration_date']) ? $_POST['expiration_date'] : null;
    
    if ($title === '') {
        throw new Exception('Link title is required');
    }
    
    if ($url === '') {
        throw new Exception('URL is required');
    }
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception('Invalid URL format');
    }
    
    if (!in_array($entityType, ['client', 'organization'])) {
        throw new Exception('Invalid entity type');
    }
    
    if ($entityId <= 0) {
        throw new Exception('Invalid entity ID');
    }
    
    $stmt = $pdo->prepare('INSERT INTO entity_links (entity_type, entity_id, title, url, link_type, expiration_date, is_expired, ignore_auto_generation) VALUES (?, ?, ?, ?, "manual", ?, 0, 0)');
    $stmt->execute([$entityType, $entityId, $title, $url, $expirationDate]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Manual link added successfully',
        'link_id' => (int)$pdo->lastInsertId()
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

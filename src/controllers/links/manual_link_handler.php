<?php
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

// Get database connection
$db = getConnection();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    // Validate required fields
    $title = $_POST['title'] ?? '';
    $url = $_POST['url'] ?? '';
    $entityType = $_POST['entity_type'] ?? '';
    $entityId = $_POST['entity_id'] ?? '';
    $expirationDate = $_POST['expiration_date'] ?? null;
    
    if (empty($title)) {
        throw new Exception('Link title is required');
    }
    
    if (empty($url)) {
        throw new Exception('URL is required');
    }
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception('Invalid URL format');
    }
    
    if (empty($entityType) || !in_array($entityType, ['client', 'organization'])) {
        throw new Exception('Invalid entity type');
    }
    
    if (empty($entityId) || !is_numeric($entityId)) {
        throw new Exception('Invalid entity ID');
    }
    
    // Build insert query
    $query = "INSERT INTO link (
        entity_type, 
        entity_id, 
        title, 
        url, 
        type, 
        expiration_date,
        is_expired,
        ignore_auto_generation,
        created_at
    ) VALUES (
        :entity_type,
        :entity_id,
        :title,
        :url,
        'manual',
        :expiration_date,
        0,
        0,
        NOW()
    )";
    
    $stmt = $db->prepare($query);
    $stmt->bindValue(':entity_type', $entityType);
    $stmt->bindValue(':entity_id', $entityId, PDO::PARAM_INT);
    $stmt->bindValue(':title', $title);
    $stmt->bindValue(':url', $url);
    $stmt->bindValue(':expiration_date', !empty($expirationDate) ? $expirationDate : null);
    
    $stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Manual link added successfully',
        'link_id' => $db->lastInsertId()
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

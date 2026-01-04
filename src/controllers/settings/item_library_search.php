<?php
// src/controllers/settings/item_library_search.php
// API endpoint for item autocomplete

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/db.php';

$query = $_GET['q'] ?? '';
$query = trim($query);

if (strlen($query) < 1) {
    echo json_encode([]);
    exit;
}

try {
    // Search for active items that match the query
    $stmt = $pdo->prepare('
        SELECT id, item_name, description, unit_price 
        FROM item_library 
        WHERE is_active = 1 
        AND item_name LIKE ? 
        ORDER BY item_name ASC 
        LIMIT 10
    ');
    
    $searchTerm = '%' . $query . '%';
    $stmt->execute([$searchTerm]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format results for autocomplete
    $formatted = array_map(function($item) {
        return [
            'id' => (int)$item['id'],
            'item_name' => $item['item_name'],
            'description' => $item['description'] ?? '',
            'unit_price' => (float)$item['unit_price']
        ];
    }, $results);

    echo json_encode($formatted);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Search failed']);
}
exit;

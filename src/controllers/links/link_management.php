<?php
// src/controllers/links/link_management.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/LinkResolverService.php';

header('Content-Type: application/json');

try {
    $action = $_POST['action'] ?? '';
    $entityType = $_POST['entity_type'] ?? '';
    $entityId = (int)($_POST['entity_id'] ?? 0);
    
    if (!in_array($action, ['generate', 'refresh', 'expire', 'ignore', 'unignore'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }
    
    if (!in_array($entityType, ['client', 'organization'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid entity type']);
        exit;
    }
    
    if ($entityId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid entity ID']);
        exit;
    }
    
    $linkService = new LinkResolverService($pdo);
    
    switch ($action) {
        case 'generate':
        case 'refresh':
            if ($entityType === 'client') {
                $result = $linkService->autoGenerateForClient($entityId);
            } else {
                $result = $linkService->autoGenerateForOrganization($entityId);
            }
            break;
            
        case 'expire':
            $result = $linkService->expireLinks($entityType, $entityId);
            break;
            
        case 'ignore':
            $result = $linkService->markAsIgnored($entityType, $entityId);
            break;
            
        case 'unignore':
            $result = $linkService->unmarkAsIgnored($entityType, $entityId);
            break;
            
        default:
            $result = ['success' => false, 'message' => 'Action not implemented'];
    }
    
    echo json_encode($result);
    
} catch (Throwable $e) {
    @error_log('[LinkManagement] Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

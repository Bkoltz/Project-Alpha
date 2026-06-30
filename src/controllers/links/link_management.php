<?php
// src/controllers/links/link_management.php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../services/LinkResolverService.php';

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
    $action = $_POST['action'] ?? '';
    $entityType = $_POST['entity_type'] ?? '';
    $entityId = (int)($_POST['entity_id'] ?? 0);
    
    if (!in_array($action, ['generate', 'refresh', 'expire', 'ignore', 'unignore'])) {
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        exit;
    }
    
    if (!in_array($entityType, ['client', 'organization', 'department', 'project'], true)) {
        echo json_encode(['success' => false, 'message' => 'Invalid entity type']);
        exit;
    }
    
    if ($entityId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid entity ID']);
        exit;
    }

    if ($entityType === 'client') {
        require_record_ownership($pdo, 'clients', $entityId);
    } elseif ($entityType === 'organization') {
        require_record_ownership($pdo, 'organizations', $entityId);
    } elseif ($entityType === 'department') {
        $dept = $pdo->prepare('SELECT organization_id FROM organization_departments WHERE id = ? LIMIT 1');
        $dept->execute([$entityId]);
        $orgId = (int)($dept->fetchColumn() ?: 0);
        if ($orgId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Department not found']);
            exit;
        }
        require_record_ownership($pdo, 'organizations', $orgId);
    } elseif ($entityType === 'project') {
        if ((string)($appConfig['project_specific_links_enabled'] ?? '0') !== '1') {
            echo json_encode(['success' => false, 'message' => 'Project-specific links are disabled']);
            exit;
        }
        require_record_ownership($pdo, 'projects', $entityId);
    }
    
    $linkService = new LinkResolverService($pdo);
    
    switch ($action) {
        case 'generate':
        case 'refresh':
            if ($entityType === 'client') {
                $result = $linkService->autoGenerateForClient($entityId);
            } elseif ($entityType === 'organization') {
                $result = $linkService->autoGenerateForOrganization($entityId);
            } else {
                $result = ['success' => false, 'message' => 'Automatic generation currently supports clients and organizations only'];
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

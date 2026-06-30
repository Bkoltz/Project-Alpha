<?php
// src/controllers/settings/link_resolver_run.php

require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../services/LinkResolverService.php';

header('Content-Type: application/json');

if (empty($_SESSION['user'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Authentication required']);
    exit;
}

if (!csrf_validate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token']);
    exit;
}

$provider = (string)($_POST['provider'] ?? '');

try {
    $service = new LinkResolverService($pdo);
    echo json_encode($service->runProviderScan($provider));
} catch (Throwable $e) {
    @error_log('[LinkResolverRun] Error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

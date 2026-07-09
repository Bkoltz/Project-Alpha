<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/tax_lookup.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $mode = strtolower(trim((string)($_GET['mode'] ?? '')));
    $stateHint = isset($_GET['state']) ? (string)$_GET['state'] : null;
    if ($mode === 'zip') {
        $result = pa_tax_lookup_by_zip($pdo, (string)($_GET['zip'] ?? ''), isset($_GET['zip4']) ? (string)$_GET['zip4'] : null, $stateHint);
        echo json_encode($result);
        exit;
    }

    if ($mode === 'county') {
        $choices = pa_tax_lookup_by_county($pdo, (string)($_GET['q'] ?? ''), 12, $stateHint);
        echo json_encode([
            'status' => $choices ? 'ok' : 'not_found',
            'message' => $choices ? 'County rates matched.' : 'No imported county rates matched that search.',
            'choices' => $choices,
        ]);
        exit;
    }

    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Unknown tax lookup mode.',
        'choices' => [],
    ]);
} catch (Throwable $e) {
    @error_log('[tax-lookup] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Tax lookup failed.',
        'choices' => [],
    ]);
}

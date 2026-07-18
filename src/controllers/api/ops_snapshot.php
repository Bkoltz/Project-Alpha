<?php

declare(strict_types=1);

use App\Services\OpsSnapshotService;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/external_ops.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$pageInput = array_key_exists('_ops_snapshot_page', $_GET)
    ? filter_var($_GET['_ops_snapshot_page'], FILTER_VALIDATE_INT)
    : 1;
$limitInput = array_key_exists('limit', $_GET)
    ? filter_var($_GET['limit'], FILTER_VALIDATE_INT)
    : OpsSnapshotService::DEFAULT_LIMIT;
$page = $pageInput;
$limit = $limitInput;

if ($page === false || $page === null || $page < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'page must be an integer greater than or equal to 1']);
    exit;
}
if ($limit === false || $limit === null || $limit < 1 || $limit > OpsSnapshotService::MAX_LIMIT) {
    http_response_code(400);
    echo json_encode(['error' => 'limit must be an integer between 1 and ' . OpsSnapshotService::MAX_LIMIT]);
    exit;
}

try {
    $snapshot = (new OpsSnapshotService())->snapshot(
        $pdo,
        (int)$page,
        (int)$limit,
        null,
        pa_external_ops_application_key($pdo)
    );
    echo json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (PDOException $error) {
    error_log('[OpsSnapshot] Database query failed: ' . $error->getMessage());
    http_response_code(503);
    echo json_encode(['error' => 'The operations snapshot is temporarily unavailable']);
} catch (Throwable $error) {
    error_log('[OpsSnapshot] Snapshot generation failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'The operations snapshot could not be generated']);
}

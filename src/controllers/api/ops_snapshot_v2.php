<?php

declare(strict_types=1);

use App\Services\OpsSnapshotV2Service;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/api_response.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Sync-Contract-Version: 2.0');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET');
    api_json_failure(405, 'method_not_allowed', 'Only GET is supported.');
}

$limitInput = array_key_exists('limit', $_GET)
    ? filter_var($_GET['limit'], FILTER_VALIDATE_INT)
    : OpsSnapshotV2Service::DEFAULT_LIMIT;
if ($limitInput === false
    || $limitInput === null
    || $limitInput < 1
    || $limitInput > OpsSnapshotV2Service::MAX_LIMIT
) {
    api_json_failure(
        400,
        'invalid_limit',
        'limit must be an integer between 1 and ' . OpsSnapshotV2Service::MAX_LIMIT . '.'
    );
}

$snapshotId = isset($_GET['snapshot_id']) ? trim((string)$_GET['snapshot_id']) : null;
$cursor = isset($_GET['cursor']) ? trim((string)$_GET['cursor']) : null;
$principal = $GLOBALS['pa_service_principal'] ?? null;
$apiKeyId = is_array($principal) ? (int)($principal['api_key_id'] ?? 0) : 0;
if ($apiKeyId < 1) {
    api_json_failure(401, 'missing_service_principal', 'A valid API key principal is required.');
}

try {
    $response = (new OpsSnapshotV2Service())->snapshot(
        $pdo,
        $apiKeyId,
        (int)$limitInput,
        $snapshotId,
        $cursor
    );
    $response['request_id'] = api_request_id();
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
} catch (InvalidArgumentException $error) {
    api_json_failure(400, 'invalid_request', $error->getMessage());
} catch (DomainException $error) {
    api_json_failure(409, 'snapshot_invalid', $error->getMessage());
} catch (UnexpectedValueException $error) {
    error_log('[OpsSnapshotV2][' . api_request_id() . '] ' . $error->getMessage());
    api_json_failure(
        503,
        'sync_state_out_of_date',
        'The v2 projection detected a mutation that was not recorded atomically.'
    );
} catch (PDOException $error) {
    error_log('[OpsSnapshotV2][' . api_request_id() . '] ' . $error->getMessage());
    api_json_failure(
        503,
        'schema_out_of_date',
        'Sync Contract v2 is unavailable until its migration has been applied.'
    );
} catch (Throwable $error) {
    error_log('[OpsSnapshotV2][' . api_request_id() . '] ' . $error->getMessage());
    api_json_failure(500, 'snapshot_failed', 'The v2 snapshot could not be generated.');
}

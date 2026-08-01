<?php

declare(strict_types=1);

require_once __DIR__ . '/../../services/NotificationRelayPolicy.php';
require_once __DIR__ . '/../../services/NotificationRelayQueue.php';
require_once __DIR__ . '/../../utils/client_ip.php';
require_once __DIR__ . '/../../utils/api_ip_allowlist.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!NotificationRelayPolicy::isEnabled()) {
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

$apiKey = $GLOBALS['pa_api_key'] ?? null;
$apiKeyId = (int)($apiKey['id'] ?? 0);
if ($apiKeyId <= 0) {
    api_json_error(401, 'Invalid API key');
}

// Internal reachability is ultimately an ingress concern. Requiring a key-level
// source allowlist prevents accidentally enabling this feature with a generic key.
if (api_parse_exact_ip_allowlist($apiKey['allowed_ips'] ?? '') === []) {
    api_json_error(403, 'Notification relay keys require an IP allowlist');
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$contentType = strtolower(trim(explode(';', (string)($_SERVER['CONTENT_TYPE'] ?? ''))[0]));
if ($contentType !== 'application/json') {
    http_response_code(415);
    echo json_encode(['error' => 'Content-Type must be application/json']);
    exit;
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 32768) {
    http_response_code(413);
    echo json_encode(['error' => 'Request body too large']);
    exit;
}
$raw = file_get_contents('php://input', false, null, 0, 32769);
if (!is_string($raw) || $raw === '' || strlen($raw) > 32768) {
    http_response_code(strlen((string)$raw) > 32768 ? 413 : 400);
    echo json_encode(['error' => strlen((string)$raw) > 32768 ? 'Request body too large' : 'Request body is required']);
    exit;
}

$queue = new NotificationRelayQueue($pdo);
$recordRejection = static function (string $reason) use ($queue, $apiKeyId): void {
    try {
        $queue->recordRejected($apiKeyId, $reason);
    } catch (Throwable $ignored) {
    }
};
try {
    $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
    if (!is_array($payload) || array_is_list($payload)) {
        throw new NotificationRelayRequestException('Request body must be a JSON object', 400, 'invalid_json_object');
    }
    $policy = NotificationRelayPolicy::load();
    $request = NotificationRelayPolicy::prepareRequest($payload, $policy);
    $result = $queue->enqueue(
        $apiKeyId,
        $request,
        $policy,
        get_client_ip(),
        (string)($_SERVER['HTTP_USER_AGENT'] ?? '')
    );
    http_response_code($result['duplicate'] ? 200 : 202);
    echo json_encode([
        'queue_id' => $result['id'],
        'status' => $result['status'],
        'duplicate' => $result['duplicate'],
    ], JSON_UNESCAPED_SLASHES);
} catch (JsonException $error) {
    $recordRejection('invalid_json');
    http_response_code(400);
    echo json_encode(['error' => 'Request body is not valid JSON']);
} catch (NotificationRelayRequestException $error) {
    $recordRejection($error->reason);
    http_response_code($error->httpStatus);
    echo json_encode(['error' => $error->getMessage()]);
} catch (Throwable $error) {
    try {
        $recordRejection('internal_error');
    } catch (Throwable $ignored) {
    }
    @error_log('[notification_relay] Enqueue failed without request data or credentials');
    http_response_code(503);
    echo json_encode(['error' => 'Notification relay is unavailable']);
}

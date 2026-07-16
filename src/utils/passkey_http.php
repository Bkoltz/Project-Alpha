<?php

declare(strict_types=1);

function passkey_request_json(): array
{
    $declaredLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($declaredLength > 262144) {
        passkey_json(false, 'request_too_large', 'The passkey response is too large.', 413);
    }
    $stream = fopen('php://input', 'rb');
    $raw = is_resource($stream) ? stream_get_contents($stream, 262145) : false;
    if (is_resource($stream)) { fclose($stream); }
    if (is_string($raw) && strlen($raw) > 262144) {
        passkey_json(false, 'request_too_large', 'The passkey response is too large.', 413);
    }
    $data = json_decode(is_string($raw) ? $raw : '', true);
    return is_array($data) ? $data : [];
}

/** @param array<string,mixed> $extra */
function passkey_json(bool $success, string $code, string $message, int $status = 200, array $extra = []): never
{
    $requestId = (string)($_SERVER['HTTP_X_REQUEST_ID'] ?? bin2hex(random_bytes(8)));
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['success' => $success, 'code' => $code, 'message' => $message, 'request_id' => $requestId] + $extra, JSON_UNESCAPED_SLASHES);
    exit;
}

function passkey_require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        passkey_json(false, 'method_not_allowed', 'POST is required.', 405);
    }
}

/** @param array<string,mixed> $data */
function passkey_require_csrf(string $scope, array $data): void
{
    $submitted = $data['_token'] ?? '';
    if (!csrf_sf_is_valid($scope, is_string($submitted) ? $submitted : '')) {
        passkey_json(false, 'csrf_invalid', 'The security token is invalid. Refresh the page and try again.', 403);
    }
}

<?php

declare(strict_types=1);

function api_request_id(): string
{
    static $id = null;
    if ($id !== null) {
        return $id;
    }
    $incoming = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
    $id = preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $incoming)
        ? $incoming
        : bin2hex(random_bytes(12));
    header('X-Request-ID: ' . $id);
    return $id;
}
function api_json_response(array $payload, int $status = 200): never
{
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($status);
    $payload['request_id'] = $payload['request_id'] ?? api_request_id();
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_NUMERIC_CHECK);
    exit;
}

function api_json_success(array $data = [], int $status = 200): never
{
    api_json_response(['success' => true] + $data, $status);
}

function api_json_failure(int $status, string $code, string $message, array $details = []): never
{
    api_json_response([
        'success' => false,
        'code' => $code,
        'message' => $message,
    ] + ($details ? ['details' => $details] : []), $status);
}

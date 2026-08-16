<?php

declare(strict_types=1);

use App\Services\PortalIntegrationContract;
use App\Services\PortalIntegrationService;
use App\Services\PortalIntegrationSecurityService;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/api_response.php';
require_once __DIR__ . '/../../utils/api_scopes.php';
require_once __DIR__ . '/../../utils/portal_integration_security.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') portal_integration_failure(405, 'METHOD_NOT_ALLOWED');
$key = (string)($_GET['_integration_key'] ?? '');
$principal = $GLOBALS['pa_service_principal'] ?? [];
if (api_normalize_scopes($principal['scopes'] ?? []) !== [PortalIntegrationContract::DRAFT_SCOPE]) {
    portal_integration_failure(403, 'SCOPE_DENIED');
}
$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > 98304) portal_integration_failure(413, 'PAYLOAD_TOO_LARGE');
$idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''));
$path = '/api/v2/integrations/' . rawurlencode($key) . '/draft-quotes';
try {
    PortalIntegrationContract::verifySignedRequest(
        $key, PortalIntegrationContract::DRAFT_SCOPE, $path, $raw, $_SERVER,
        portal_integration_hmac_secret($key, 'draft')
    );
    (new PortalIntegrationSecurityService())->claim($pdo,$key,(int)($principal['api_key_id']??0),PortalIntegrationContract::DRAFT_SCOPE,hash('sha256',$raw),(string)($_SERVER['HTTP_X_PORTAL_INTEGRATION_SIGNATURE']??''),$idempotencyKey,api_get_client_ip());
    $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($body) || array_is_list($body)) throw new DomainException('draft-request-invalid');
    $result = (new PortalIntegrationService())->createDraftQuote(
        $pdo, (int)($principal['api_key_id'] ?? 0), $key, $idempotencyKey, hash('sha256', $raw), $body
    );
    if(in_array((int)$result['status'],[200,201],true))PortalIntegrationContract::validateDraftResponse($result['body']);
    http_response_code($result['status']);
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException|DomainException $error) {
    $code = $error->getMessage();
    $status = str_contains($code, 'signature') || str_contains($code, 'timestamp') || str_contains($code, 'signing-key') ? 401
        : (str_contains($code,'rate-limited')?429:(str_contains($code, 'scope') || str_contains($code, 'source-denied') ? 403 : (str_contains($code, 'stale')||str_contains($code,'replay') ? 409 : 422)));
    portal_integration_failure($status, strtoupper(str_replace('-', '_', $code)));
} catch (Throwable $error) {
    error_log('[PortalDraftQuote][' . api_request_id() . '] ' . get_class($error));
    portal_integration_failure(503, 'DRAFT_QUOTE_UNAVAILABLE');
}

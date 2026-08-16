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
if (api_normalize_scopes($principal['scopes'] ?? []) !== [PortalIntegrationContract::PRICING_SCOPE]) {
    portal_integration_failure(403, 'SCOPE_DENIED');
}
$raw = file_get_contents('php://input');
if (!is_string($raw) || strlen($raw) > 16384) portal_integration_failure(413, 'PAYLOAD_TOO_LARGE');
$path = '/api/v2/integrations/' . rawurlencode($key) . '/pricing-hints';
try {
    PortalIntegrationContract::verifySignedRequest(
        $key, PortalIntegrationContract::PRICING_SCOPE, $path, $raw, $_SERVER,
        portal_integration_hmac_secret($key, 'pricing')
    );
    (new PortalIntegrationSecurityService())->claim($pdo,$key,(int)($principal['api_key_id']??0),PortalIntegrationContract::PRICING_SCOPE,hash('sha256',$raw),(string)($_SERVER['HTTP_X_PORTAL_INTEGRATION_SIGNATURE']??''),'',api_get_client_ip());
    $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($body) || array_is_list($body)) throw new DomainException('pricing-request-invalid');
    $response = (new PortalIntegrationService())->pricingHint($pdo, (int)($principal['api_key_id'] ?? 0), $key, $body);
    PortalIntegrationContract::validatePricingResponse($response);
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException|DomainException $error) {
    $code = $error->getMessage();
    $status = str_contains($code, 'signature') || str_contains($code, 'timestamp') || str_contains($code, 'signing-key') ? 401
        : (str_contains($code,'rate-limited')?429:(str_contains($code, 'scope') || str_contains($code, 'source-denied') ? 403 : (str_contains($code, 'stale')||str_contains($code,'replay') ? 409 : 422)));
    portal_integration_failure($status, strtoupper(str_replace('-', '_', $code)));
} catch (Throwable $error) {
    error_log('[PortalPricing][' . api_request_id() . '] ' . get_class($error));
    portal_integration_failure(503, 'PRICING_UNAVAILABLE');
}

<?php

declare(strict_types=1);

use App\Services\PortalIntegrationContract;
use App\Services\PortalIntegrationAuditService;
use App\Services\PortalIntegrationService;
use App\Services\PortalIntegrationSecurityService;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/api_response.php';
require_once __DIR__ . '/../../utils/api_scopes.php';
require_once __DIR__ . '/../../utils/portal_integration_security.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
$key = (string)($_GET['_integration_key'] ?? '');
$principal = $GLOBALS['pa_service_principal'] ?? [];
$apiKeyId=(int)($principal['api_key_id']??0);$correlationId=api_request_id();$audit=new PortalIntegrationAuditService();
$path = '/api/v2/integrations/' . rawurlencode($key) . '/draft-quotes';
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new DomainException('method-not-allowed');
    if(api_normalize_scopes($principal['scopes']??[])!==[PortalIntegrationContract::DRAFT_SCOPE])throw new DomainException('scope-denied');
    $raw=file_get_contents('php://input');if(!is_string($raw)||strlen($raw)>98304)throw new DomainException('payload-too-large');
    $idempotencyKey=trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY']??''));
    PortalIntegrationContract::verifySignedRequest(
        $key, PortalIntegrationContract::DRAFT_SCOPE, $path, $raw, $_SERVER,
        portal_integration_hmac_secrets($key, 'draft')
    );
    (new PortalIntegrationSecurityService())->claim($pdo,$key,$apiKeyId,PortalIntegrationContract::DRAFT_SCOPE,hash('sha256',$raw),(string)($_SERVER['HTTP_X_PORTAL_INTEGRATION_SIGNATURE']??''),$idempotencyKey,api_get_client_ip());
    $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($body) || array_is_list($body)) throw new DomainException('draft-request-invalid');
    $result = (new PortalIntegrationService())->createDraftQuote(
        $pdo,$apiKeyId,$key,$idempotencyKey,hash('sha256',$raw),$body,$correlationId
    );
    if(in_array((int)$result['status'],[200,201],true))PortalIntegrationContract::validateDraftResponse($result['body']);
    else PortalIntegrationContract::validateDraftErrorResponse((int)$result['status'],$result['body']);
    http_response_code($result['status']);
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException|DomainException $error) {
    $code = $error->getMessage();
    $status=str_contains($code,'method')?405:(str_contains($code,'payload-too-large')?413:(str_contains($code,'signature')||str_contains($code,'timestamp')||str_contains($code,'signing-key')?401:(str_contains($code,'rate-limited')?429:(str_contains($code,'scope')||str_contains($code,'source-denied')?403:(str_contains($code,'stale')||str_contains($code,'replay')?409:422)))));
    $outcome=str_contains($code,'replay')?'replayed':(str_contains($code,'stale')?'conflicted':(in_array($status,[401,403,405,413,422,429],true)?'denied':'failed'));
    try{$audit->recordCommand($pdo,$key,$apiKeyId,PortalIntegrationContract::DRAFT_SCOPE,$outcome,$correlationId,$code);}catch(Throwable$auditError){error_log('[PortalDraftQuote]['.$correlationId.'] audit='.get_class($auditError));portal_integration_failure(503,'AUDIT_UNAVAILABLE');}
    portal_integration_failure($status, strtoupper(str_replace('-', '_', $code)));
} catch (Throwable $error) {
    error_log('[PortalDraftQuote][' . api_request_id() . '] ' . get_class($error));
    try{$audit->recordCommand($pdo,$key,$apiKeyId,PortalIntegrationContract::DRAFT_SCOPE,'failed',$correlationId,'DRAFT_QUOTE_UNAVAILABLE');}catch(Throwable$auditError){error_log('[PortalDraftQuote]['.$correlationId.'] audit='.get_class($auditError));portal_integration_failure(503,'AUDIT_UNAVAILABLE');}
    portal_integration_failure(503, 'DRAFT_QUOTE_UNAVAILABLE');
}

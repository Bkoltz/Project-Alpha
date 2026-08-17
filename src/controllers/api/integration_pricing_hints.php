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
$path = '/api/v2/integrations/' . rawurlencode($key) . '/pricing-hints';
try {
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new DomainException('method-not-allowed');
    if(api_normalize_scopes($principal['scopes']??[])!==[PortalIntegrationContract::PRICING_SCOPE])throw new DomainException('scope-denied');
    $raw=file_get_contents('php://input');if(!is_string($raw)||strlen($raw)>16384)throw new DomainException('payload-too-large');
    PortalIntegrationContract::verifySignedRequest(
        $key, PortalIntegrationContract::PRICING_SCOPE, $path, $raw, $_SERVER,
        portal_integration_hmac_secrets($key, 'pricing')
    );
    (new PortalIntegrationSecurityService())->claim($pdo,$key,$apiKeyId,PortalIntegrationContract::PRICING_SCOPE,hash('sha256',$raw),(string)($_SERVER['HTTP_X_PORTAL_INTEGRATION_SIGNATURE']??''),'',api_get_client_ip());
    $body = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($body) || array_is_list($body)) throw new DomainException('pricing-request-invalid');
    $response = (new PortalIntegrationService())->pricingHint($pdo,$apiKeyId,$key,$body);
    PortalIntegrationContract::validatePricingResponse($response);
    $audit->recordCommand($pdo,$key,$apiKeyId,PortalIntegrationContract::PRICING_SCOPE,'allowed',$correlationId,null,'project',(string)($body['authorizationContext']['projectPublicId']??''),['display_mode'=>$response['displayMode'],'service_count'=>count($body['services']??[])]);
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (JsonException|DomainException $error) {
    $code = $error->getMessage();
    $status=str_contains($code,'method')?405:(str_contains($code,'payload-too-large')?413:(str_contains($code,'signature')||str_contains($code,'timestamp')||str_contains($code,'signing-key')?401:(str_contains($code,'rate-limited')?429:(str_contains($code,'scope')||str_contains($code,'source-denied')?403:(str_contains($code,'stale')||str_contains($code,'replay')?409:422)))));
    $outcome=str_contains($code,'replay')?'replayed':(str_contains($code,'stale')?'conflicted':(in_array($status,[401,403,405,413,422,429],true)?'denied':'failed'));
    try{$audit->recordCommand($pdo,$key,$apiKeyId,PortalIntegrationContract::PRICING_SCOPE,$outcome,$correlationId,$code);}catch(Throwable$auditError){error_log('[PortalPricing]['.$correlationId.'] audit='.get_class($auditError));portal_integration_failure(503,'AUDIT_UNAVAILABLE');}
    portal_integration_failure($status, strtoupper(str_replace('-', '_', $code)));
} catch (Throwable $error) {
    error_log('[PortalPricing][' . api_request_id() . '] ' . get_class($error));
    try{$audit->recordCommand($pdo,$key,$apiKeyId,PortalIntegrationContract::PRICING_SCOPE,'failed',$correlationId,'PRICING_UNAVAILABLE');}catch(Throwable$auditError){error_log('[PortalPricing]['.$correlationId.'] audit='.get_class($auditError));portal_integration_failure(503,'AUDIT_UNAVAILABLE');}
    portal_integration_failure(503, 'PRICING_UNAVAILABLE');
}

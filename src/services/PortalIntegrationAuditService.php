<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;

/** Durable, redacted command outcomes. Audit writes deliberately fail closed. */
final class PortalIntegrationAuditService
{
    /** @param array<string,mixed> $metadata */
    public function recordCommand(
        PDO $pdo,
        string $applicationKey,
        int $apiKeyId,
        string $capability,
        string $outcome,
        string $correlationId,
        ?string $code=null,
        ?string $targetType=null,
        ?string $targetPublicId=null,
        array $metadata=[]
    ):void {
        if(!in_array($outcome,['allowed','denied','replayed','conflicted','failed'],true))throw new DomainException('portal-audit-outcome-invalid');
        if(preg_match('/^[A-Za-z0-9._:-]{8,100}$/D',$correlationId)!==1)throw new DomainException('portal-audit-correlation-invalid');
        $profile=$pdo->prepare('SELECT id FROM portal_integration_profiles WHERE application_key=?');$profile->execute([$applicationKey]);$profileId=$profile->fetchColumn();
        $safe=['capability'=>$capability];
        if($code!==null)$safe['code']=substr(preg_replace('/[^A-Z0-9_:-]/','_',strtoupper($code))??'FAILED',0,64);
        foreach($metadata as$key=>$value){if(is_string($key)&&preg_match('/^[a-z][a-z0-9_]{0,31}$/D',$key)===1&&(is_null($value)||is_bool($value)||is_int($value)||(is_string($value)&&strlen($value)<=191)))$safe[$key]=$value;}
        $statement=$pdo->prepare('INSERT INTO portal_integration_audit (integration_profile_id,api_key_id,correlation_id,action,outcome,target_type,target_public_id,metadata_json) VALUES (?,?,?,?,?,?,?,?)');
        $statement->execute([$profileId===false?null:(int)$profileId,$apiKeyId>0?$apiKeyId:null,$correlationId,$capability,$outcome,$targetType,$targetPublicId,json_encode($safe,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    }
}

<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;
use PDO;
use Throwable;

final class PortalIntegrationSecurityService
{
    public function claim(PDO $pdo,string $applicationKey,int $apiKeyId,string $capability,string $bodyHash,string $signature,string $idempotencyKey,string $sourceIp):void
    {
        $flag=$capability===PortalIntegrationContract::PRICING_SCOPE?'pricing_preview_enabled':'draft_quote_enabled';
        $profile=(new PortalIntegrationService())->profile($pdo,$applicationKey,$flag);
        $limit=$capability===PortalIntegrationContract::PRICING_SCOPE?30:10;
        $window=intdiv(time(),60);$sourceHash=hash('sha256',trim($sourceIp));
        if($pdo->inTransaction())throw new DomainException('integration-security-transaction-active');
        // Admission is intentionally committed before receipt/replay validation. A denied replay
        // must still consume its abuse-control budget; rolling this back makes replay floods free.
        $this->consumeAdmission($pdo,(int)$profile['id'],$apiKeyId,$capability,$sourceHash,$window,$limit);
        try{
            $pdo->beginTransaction();
            $signatureHash=hash('sha256',$signature);$idempotencyHash=$idempotencyKey===''?null:hash('sha256',$idempotencyKey);
            $existing=$pdo->prepare('SELECT id,body_hash,idempotency_hash,replay_count FROM portal_integration_request_receipts WHERE integration_profile_id=? AND api_key_id=? AND capability=? AND signature_hash=?');$existing->execute([(int)$profile['id'],$apiKeyId,$capability,$signatureHash]);$receipt=$existing->fetch(PDO::FETCH_ASSOC);
            if($receipt){
                if(!hash_equals((string)$receipt['body_hash'],$bodyHash)||($receipt['idempotency_hash']!==null&&!hash_equals((string)$receipt['idempotency_hash'],(string)$idempotencyHash)))throw new DomainException('integration-replay-conflict');
                if($capability===PortalIntegrationContract::PRICING_SCOPE)throw new DomainException('integration-replay-denied');
                $pdo->prepare('UPDATE portal_integration_request_receipts SET replay_count=replay_count+1,last_seen_at=CURRENT_TIMESTAMP WHERE id=?')->execute([(int)$receipt['id']]);
            }else{
                $pdo->prepare('INSERT INTO portal_integration_request_receipts (integration_profile_id,api_key_id,capability,signature_hash,idempotency_hash,body_hash) VALUES (?,?,?,?,?,?)')->execute([(int)$profile['id'],$apiKeyId,$capability,$signatureHash,$idempotencyHash,$bodyHash]);
            }
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }

    private function consumeAdmission(PDO $pdo,int $profileId,int $apiKeyId,string $capability,string $sourceHash,int $window,int $limit):void
    {
        try{
            $pdo->beginTransaction();
            $driver=$pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if($driver==='sqlite')$sql='INSERT INTO portal_integration_rate_buckets (integration_profile_id,api_key_id,capability,source_hash,window_minute,request_count) VALUES (?,?,?,?,?,1) ON CONFLICT(integration_profile_id,api_key_id,capability,source_hash,window_minute) DO UPDATE SET request_count=request_count+1';
            else$sql='INSERT INTO portal_integration_rate_buckets (integration_profile_id,api_key_id,capability,source_hash,window_minute,request_count) VALUES (?,?,?,?,?,1) ON DUPLICATE KEY UPDATE request_count=request_count+1';
            $pdo->prepare($sql)->execute([$profileId,$apiKeyId,$capability,$sourceHash,$window]);
            $count=$pdo->prepare('SELECT request_count FROM portal_integration_rate_buckets WHERE integration_profile_id=? AND api_key_id=? AND capability=? AND source_hash=? AND window_minute=?');$count->execute([$profileId,$apiKeyId,$capability,$sourceHash,$window]);$admitted=(int)$count->fetchColumn();
            $pdo->commit();
            if($admitted>$limit)throw new DomainException('integration-rate-limited');
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }
}

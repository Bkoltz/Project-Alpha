<?php

declare(strict_types=1);

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;
use PDO;

final class PortalIntegrationMaintenanceService
{
    public const RATE_RETENTION_MINUTES = 2880;
    public const RECEIPT_RETENTION_HOURS = 24;
    public const MAX_ROWS_PER_RUN = 100000;
    public const MAX_PASSES_PER_RUN = 40;
    public const MAX_RUNTIME_MILLISECONDS = 2500;

    /** Bounded deletes keep legitimate traffic and abuse from growing security state forever. */
    public function prune(PDO $pdo,int $batch=5000):array
    {
        $batch=max(1,min(5000,$batch));
        $rateCutoff=intdiv(time(),60)-self::RATE_RETENTION_MINUTES;
        $receiptCutoff=(new DateTimeImmutable('-'.self::RECEIPT_RETENTION_HOURS.' hours',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');
        $started=microtime(true);$passes=0;$total=0;$rateTotal=0;$receiptTotal=0;$rateDone=false;$receiptDone=false;
        while($passes<self::MAX_PASSES_PER_RUN&&$total<self::MAX_ROWS_PER_RUN&&(microtime(true)-$started)*1000<self::MAX_RUNTIME_MILLISECONDS&&(!$rateDone||!$receiptDone)){
            $remaining=self::MAX_ROWS_PER_RUN-$total;$limit=min($batch,$remaining);if($limit<1)break;
            if(!$rateDone){$rate=$pdo->prepare('DELETE FROM portal_integration_rate_buckets WHERE window_minute<? ORDER BY window_minute,integration_profile_id,api_key_id,capability,source_hash LIMIT '.$limit);$rate->execute([$rateCutoff]);$deleted=$rate->rowCount();$rateTotal+=$deleted;$total+=$deleted;$rateDone=$deleted<$limit;$passes++;}
            $remaining=self::MAX_ROWS_PER_RUN-$total;$limit=min($batch,$remaining);if($limit<1)break;
            if(!$receiptDone){$receipts=$pdo->prepare('DELETE FROM portal_integration_request_receipts WHERE last_seen_at<? ORDER BY last_seen_at,id LIMIT '.$limit);$receipts->execute([$receiptCutoff]);$deleted=$receipts->rowCount();$receiptTotal+=$deleted;$total+=$deleted;$receiptDone=$deleted<$limit;$passes++;}
        }
        return['rateBuckets'=>$rateTotal,'requestReceipts'=>$receiptTotal,'passes'=>$passes,'capped'=>!($rateDone&&$receiptDone)];
    }
}

<?php

declare(strict_types=1);

use App\Services\PortalIntegrationMaintenanceService;

require_once dirname(__DIR__,2).'/vendor/autoload.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../utils/cron_state.php';

$jobName='portal_integration_security_prune';
try{$counts=(new PortalIntegrationMaintenanceService())->prune($pdo);cron_state_mark_success($pdo,$jobName,sprintf('Pruned %d rate buckets and %d request receipts',$counts['rateBuckets'],$counts['requestReceipts']));exit(0);}catch(Throwable$error){cron_state_mark_failure($pdo,$jobName,$error);error_log('[portal_integration_security_prune] Failed: '.get_class($error));exit(1);}

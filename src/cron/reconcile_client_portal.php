<?php

declare(strict_types=1);

use App\Services\ExternalOpsConfigService;
use App\Services\PortalClientProvisioningService;

require_once dirname(__DIR__,2).'/vendor/autoload.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../utils/cron_state.php';

$jobName='portal_client_provisioning_backfill';
try {
    $config=(new ExternalOpsConfigService())->load($pdo);
    $summary=(new PortalClientProvisioningService())->reconcileHistoricalBatch(
        $pdo,
        (string)($config['application_key']??''),
        25
    );
    $message=sprintf(
        'Ready %s; considered %d; completed %d; retrying %d; failed %d; remaining %d',
        $summary['ready']?'yes':'no',
        $summary['considered'],
        $summary['completed'],
        $summary['retrying'],
        $summary['failed'],
        $summary['remaining']
    );
    if ($summary['failed']>0) {
        cron_state_mark_failure($pdo,$jobName,new RuntimeException($message.'; repair the root failure and run the audited reconcile action.'));
    } else {
        cron_state_mark_success($pdo,$jobName,$message);
    }
    error_log('[portal_client_provisioning_backfill] '.$message);
    exit($summary['failed']>0 ? 1 : 0);
} catch (Throwable $error) {
    $code=substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,12);
    error_log('[portal_client_provisioning_backfill] failed code='.$code);
    cron_state_mark_failure($pdo,$jobName,new RuntimeException('Portal client provisioning backfill failed; diagnostic code '.$code));
    exit(1);
}

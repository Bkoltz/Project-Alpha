<?php

declare(strict_types=1);

use App\Services\PortalProjectionOutboxSender;

require_once dirname(__DIR__,2).'/vendor/autoload.php';
require_once __DIR__.'/../config/db.php';
require_once __DIR__.'/../utils/cron_state.php';

$jobName='portal_projection_outbox';
try{$summary=(new PortalProjectionOutboxSender())->deliverDue($pdo,50);$message=sprintf('Processed %d; delivered %d; retrying %d; dead-lettered %d',$summary['processed'],$summary['delivered'],$summary['failed'],$summary['dead_lettered']);if($summary['dead_lettered']>0)throw new RuntimeException($message);cron_state_mark_success($pdo,$jobName,$message);error_log('[portal_projection_outbox] '.$message);exit(0);}catch(Throwable$error){$code=substr(hash('sha256',get_class($error).':'.$error->getMessage()),0,12);error_log('[portal_projection_outbox] failed code='.$code);cron_state_mark_failure($pdo,$jobName,new RuntimeException('Portal projection delivery failed; diagnostic code '.$code));exit(1);}

<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/alphaledger_time_bridge.php';

if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
csrf_verify_post_or_redirect('time-tracking');
$userId=(int)($_SESSION['user']['id']??0); $context=pa_al_time_admin_context($pdo,$userId);
if(!$context){header('Location: /?page=time-tracking&error='.rawurlencode('AlphaLedger self-entry is not available for this account.'));exit;}
$action=trim((string)($_POST['action']??'')); $entryId=trim((string)($_POST['entry_id']??''));
if(!in_array($action,['update','assign','submit','cancel','retry','cancel-pending'],true)){header('Location: /?page=time-tracking&error='.rawurlencode('Unsupported AlphaLedger time action.'));exit;}
try{
    if(in_array($action,['retry','cancel-pending'],true)){
        $commandId=(int)($_POST['command_id']??0);
        $stmt=$pdo->prepare('SELECT * FROM alphaledger_command_outbox WHERE id=? AND actor_user_id=? LIMIT 1');$stmt->execute([$commandId,$userId]);$command=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$command)throw new DomainException('Pending command was not found.');
        if($action==='retry')$pdo->prepare("UPDATE alphaledger_command_outbox SET state='pending',next_attempt_at=UTC_TIMESTAMP(),last_error=NULL WHERE id=? AND state='attention'")->execute([$commandId]);
        else $pdo->prepare("UPDATE alphaledger_command_outbox SET state='cancelled',last_error='Cancelled by PA administrator.' WHERE id=? AND state IN ('pending','attention')")->execute([$commandId]);
    }else{
        if(!preg_match('/^[0-9a-f-]{36}$/i',$entryId))throw new DomainException('A valid AlphaLedger entry is required.');
        $stmt=$pdo->prepare("SELECT external_id,status,project_external_id FROM alphaledger_ledger_time_entries WHERE installation_id=? AND external_id=? AND employee_external_id=? AND status IN ('running','review','rejected') AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([(int)$context['installation']['id'],$entryId,(string)$context['al_employee_id']]);
        $ownedEntry=$stmt->fetch(PDO::FETCH_ASSOC);if(!$ownedEntry)throw new DomainException('Only your own pre-approval AlphaLedger entries can be changed.');
        if($action==='submit'&&empty($ownedEntry['project_external_id']))throw new DomainException('Assign a mapped PA project before submitting this time entry.');
        $operation=$action==='assign'?'assign':$action;
        $payload=['entry_id'=>$entryId];
        if(in_array($action,['update','assign'],true)){
            $payload['description']=trim((string)($_POST['description']??''));
            $payload['project_id']=pa_al_time_al_project_id($pdo,$context['installation'],(int)($_POST['project_id']??0)?:null);
        }
        pa_al_time_queue_command($pdo,$context,$operation,$payload,null,null,$entryId);
        pa_al_time_deliver_commands($pdo,1);
    }
    header('Location: /?page=time-tracking&created=1');
}catch(Throwable $e){header('Location: /?page=time-tracking&error='.rawurlencode($e->getMessage()));}
exit;

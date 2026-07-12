<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/csrf.php';
require_once __DIR__ . '/../../utils/audit.php';
require_once __DIR__ . '/../../utils/alphaledger_time_bridge.php';

if(($_SERVER['REQUEST_METHOD']??'')!=='POST'){http_response_code(405);exit;}
csrf_verify_post_or_redirect('settings/alphaledger');
$userId=(int)($_SESSION['user']['id']??0);
if(($_SESSION['user']['role']??'')!=='admin'||$userId<=0){http_response_code(403);exit;}
$installation=pa_al_time_active_installation($pdo); $action=trim((string)($_POST['action']??''));
if(!$installation){header('Location: /?page=settings/alphaledger&error='.rawurlencode('AlphaLedger is not connected.'));exit;}
try{
    if($action==='map-employee'){
        $alId=trim((string)($_POST['al_employee_id']??'')); $teamId=(int)($_POST['team_member_id']??0);
        $source=$pdo->prepare('SELECT display_name,email FROM alphaledger_ledger_people WHERE installation_id=? AND external_id=? AND deleted_at IS NULL LIMIT 1');$source->execute([(int)$installation['id'],$alId]);if(!$source->fetch())throw new DomainException('AlphaLedger employee was not found in the operational ledger.');
        if($teamId<=0){
            $name=trim((string)($_POST['display_name']??''));$email=trim((string)($_POST['email']??''))?:null;if($name==='')throw new DomainException('Team-member name is required.');
            $pdo->prepare('INSERT INTO team_members (organization_id,display_name,email) VALUES (?,?,?)')->execute([$installation['organization_id']??null,$name,$email]);$teamId=(int)$pdo->lastInsertId();
        }
        $pdo->prepare('INSERT INTO alphaledger_employee_mappings (installation_id,al_business_id,al_employee_id,team_member_id,confirmed_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE team_member_id=VALUES(team_member_id),confirmed_by=VALUES(confirmed_by),confirmed_at=UTC_TIMESTAMP()')->execute([(int)$installation['id'],(string)$installation['al_business_id'],$alId,$teamId,$userId]);
        $pdo->prepare("UPDATE alphaledger_integration_exceptions SET status='resolved',resolved_by=?,resolved_at=UTC_TIMESTAMP() WHERE installation_id=? AND exception_type='unmapped_employee' AND (source_object_id=? OR JSON_UNQUOTE(JSON_EXTRACT(details,'$.external_id'))=?) AND status='open'")->execute([$userId,(int)$installation['id'],$alId,$alId]);
        audit_log($pdo,'alphaledger.employee_mapped','team_member',$teamId,['al_employee_id'=>$alId]);
    }elseif($action==='map-project'){
        $alId=trim((string)($_POST['al_project_id']??''));$projectId=(int)($_POST['project_id']??0);
        $source=$pdo->prepare('SELECT name FROM alphaledger_ledger_projects WHERE installation_id=? AND external_id=? AND deleted_at IS NULL LIMIT 1');$source->execute([(int)$installation['id'],$alId]);if(!$source->fetch())throw new DomainException('AlphaLedger project was not found in the operational ledger.');
        if($projectId<=0){$name=trim((string)($_POST['project_name']??''));if($name==='')throw new DomainException('Project name is required.');$pdo->prepare("INSERT INTO projects (organization_id,name,status,created_by) VALUES (?,?,'not_started',?)")->execute([$installation['organization_id']??null,$name,$userId]);$projectId=(int)$pdo->lastInsertId();}
        $pdo->prepare('INSERT INTO alphaledger_project_mappings (installation_id,al_business_id,al_project_id,project_id,confirmed_by) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE project_id=VALUES(project_id),confirmed_by=VALUES(confirmed_by),confirmed_at=UTC_TIMESTAMP()')->execute([(int)$installation['id'],(string)$installation['al_business_id'],$alId,$projectId,$userId]);
        $pdo->prepare("UPDATE alphaledger_integration_exceptions SET status='resolved',resolved_by=?,resolved_at=UTC_TIMESTAMP() WHERE installation_id=? AND exception_type='unmapped_project' AND (source_object_id=? OR JSON_UNQUOTE(JSON_EXTRACT(details,'$.external_id'))=?) AND status='open'")->execute([$userId,(int)$installation['id'],$alId,$alId]);
        audit_log($pdo,'alphaledger.project_mapped','project',$projectId,['al_project_id'=>$alId]);
    }elseif($action==='add-member-rate'){
        $teamId=(int)($_POST['team_member_id']??0);$type=(string)($_POST['rate_type']??'');$amount=(float)($_POST['amount']??-1);$from=(string)($_POST['effective_from']??'');$until=trim((string)($_POST['effective_until']??''))?:null;
        if($teamId<=0||!in_array($type,['cost','billing'],true)||$amount<0||strtotime($from)===false||($until&&strtotime($until)===false))throw new DomainException('Invalid team-member rate.');
        $pdo->prepare('INSERT INTO team_member_rates (team_member_id,rate_type,amount,currency,effective_from,effective_until,created_by) VALUES (?,?,?,?,?,?,?)')->execute([$teamId,$type,$amount,'USD',$from,$until,$userId]);
    }elseif($action==='add-billing-rate'){
        $scope=(string)($_POST['scope_type']??'');$scopeId=(int)($_POST['scope_id']??0);$amount=(float)($_POST['amount']??-1);$from=(string)($_POST['effective_from']??'');$until=trim((string)($_POST['effective_until']??''))?:null;
        if(!in_array($scope,['client','project'],true)||$scopeId<=0||$amount<0||strtotime($from)===false)throw new DomainException('Invalid billing-rate rule.');
        $pdo->prepare('INSERT INTO billing_rate_rules (organization_id,scope_type,client_id,project_id,amount,currency,effective_from,effective_until,created_by) VALUES (?,?,?,?,?,?,?,?,?)')->execute([$installation['organization_id']??null,$scope,$scope==='client'?$scopeId:null,$scope==='project'?$scopeId:null,$amount,'USD',$from,$until,$userId]);
    }elseif($action==='resolve-exception'){
        $id=(int)($_POST['exception_id']??0);$pdo->prepare("UPDATE alphaledger_integration_exceptions SET status='cancelled',resolved_by=?,resolved_at=UTC_TIMESTAMP() WHERE id=? AND status='open'")->execute([$userId,$id]);
    }elseif(in_array($action,['backfill-preview','backfill-request'],true)){
        $from=(string)($_POST['date_from']??'');$to=(string)($_POST['date_to']??'');if(strtotime($from)===false||strtotime($to)===false||$from>$to)throw new DomainException('Choose a valid backfill date range.');
        if($action==='backfill-request'&&!in_array('time_backfill_v1',pa_al_time_capabilities($installation),true))throw new DomainException('AlphaLedger did not advertise time_backfill_v1.');
        $countStmt=$pdo->prepare("SELECT COUNT(*) FROM alphaledger_ledger_time_entries WHERE installation_id=? AND status='approved' AND DATE(start_time) BETWEEN ? AND ? AND deleted_at IS NULL");$countStmt->execute([(int)$installation['id'],$from,$to]);$preview=(int)$countStmt->fetchColumn();
        $pdo->prepare("INSERT INTO alphaledger_backfill_runs (installation_id,requested_by,date_from,date_to,state,preview_count,requested_at) VALUES (?,?,?,?,?,?,CASE WHEN ?='requested' THEN UTC_TIMESTAMP() ELSE NULL END)")->execute([(int)$installation['id'],$userId,$from,$to,$action==='backfill-request'?'requested':'previewed',$preview,$action==='backfill-request'?'requested':'previewed']);$runId=(int)$pdo->lastInsertId();
        if($action==='backfill-request'){
            $adminContext=pa_al_time_admin_context($pdo,$userId);
            $context=['installation'=>$installation,'user_id'=>$userId,'team_member_id'=>$adminContext['team_member_id']??0,'al_employee_id'=>$adminContext['al_employee_id']??''];
            if((int)$context['team_member_id']<=0){$tm=$pdo->prepare('SELECT id FROM team_members WHERE user_id=?');$tm->execute([$userId]);$context['team_member_id']=(int)$tm->fetchColumn();}
            pa_al_time_queue_command($pdo,$context,'backfill_request',['backfill_run_id'=>$runId,'date_from'=>$from,'date_to'=>$to,'statuses'=>['approved']]);pa_al_time_deliver_commands($pdo,1);
        }
    }else throw new DomainException('Unknown AlphaLedger administration action.');
    header('Location: /?page=settings/alphaledger&success='.rawurlencode('AlphaLedger time configuration updated.'));
}catch(Throwable $e){header('Location: /?page=settings/alphaledger&error='.rawurlencode($e->getMessage()));}
exit;

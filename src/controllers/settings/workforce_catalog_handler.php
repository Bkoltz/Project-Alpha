<?php

declare(strict_types=1);

use App\Services\JobWorkPlanningService;
use App\Services\CompensationRuleService;
use App\Services\PayPeriodService;

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/csrf.php';

$userId=(int)($_SESSION['user']['id']??0);$action=(string)($_POST['action']??'');$tab=(string)($_POST['return_tab']??'work-types');
$redirect='/?page=settings&tab='.rawurlencode($tab);
if($userId<=0||!csrf_validate()||!(in_array((string)($_SESSION['user']['role']??''),['admin','owner'],true)||user_can($pdo,$userId,'workforce.manage',0))){http_response_code(403);exit('Forbidden');}
try{
 if($action==='save-worker-profile'){
  $display=trim((string)($_POST['display_name']??''));$relationship=strtolower(trim((string)($_POST['relationship_type']??'employee')));$accountId=(int)($_POST['user_id']??0)?:null;
  if($display===''||!preg_match('/^[a-z0-9_-]{2,50}$/',$relationship))throw new DomainException('Enter a worker name and valid relationship type.');
  $pdo->prepare('INSERT INTO worker_profiles (user_id,relationship_type,status,display_name,currency) VALUES (?,?,"active",?,?)')->execute([$accountId,$relationship,$display,strtoupper((string)($_POST['currency']??'USD'))]);
 }elseif($action==='save-work-type'){
  $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$code=strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',trim((string)($_POST['code']??$name))));
  $method=(string)($_POST['method']??'nonpayable');$basis=(string)($_POST['percentage_basis']??'net_line');$trigger=(string)($_POST['eligibility_trigger']??'completed_approved');
  if($name===''||$code==='')throw new DomainException('Name and code are required.');
  if(!in_array($method,['nonpayable','hourly','fixed','base_overage','percentage'],true))throw new DomainException('Invalid compensation method.');
  $values=[$name,$code,trim((string)($_POST['description']??''))?:null,isset($_POST['is_active'])?1:0,$method,($_POST['amount']??'')===''?null:max(0,(float)$_POST['amount']),($_POST['included_minutes']??'')===''?null:max(0,(int)$_POST['included_minutes']),($_POST['overage_rate']??'')===''?null:max(0,(float)$_POST['overage_rate']),($_POST['percentage']??'')===''?null:min(100,max(0,(float)$_POST['percentage'])),$basis,$trigger];
  if($basis==='cash_collected'&&$method==='percentage'&&$trigger!=='invoice_paid')throw new DomainException('Cash-collected percentages require the invoice-paid trigger.');
  if($id){$pdo->prepare('UPDATE work_types SET name=?,code=?,description=?,is_active=?,default_compensation_method=?,default_amount=?,default_base_minutes=?,default_overage_rate=?,default_percentage=?,default_percentage_basis=?,default_eligibility_trigger=? WHERE id=?')->execute(array_merge($values,[$id]));}
  else{$pdo->prepare('INSERT INTO work_types (name,code,description,is_active,default_compensation_method,default_amount,default_base_minutes,default_overage_rate,default_percentage,default_percentage_basis,default_eligibility_trigger,created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute(array_merge($values,[$userId]));}
 }elseif($action==='save-business-unit'){
  $id=(int)($_POST['id']??0);$name=trim((string)($_POST['name']??''));$code=strtoupper(preg_replace('/[^A-Za-z0-9]+/','_',trim((string)($_POST['code']??$name))));if($name===''||$code==='')throw new DomainException('Name and code are required.');
  if($id)$pdo->prepare('UPDATE business_units SET name=?,code=?,description=?,is_active=? WHERE id=?')->execute([$name,$code,trim((string)($_POST['description']??''))?:null,isset($_POST['is_active'])?1:0,$id]);
  else $pdo->prepare('INSERT INTO business_units (name,code,description,is_active,created_by) VALUES (?,?,?,?,?)')->execute([$name,$code,trim((string)($_POST['description']??''))?:null,isset($_POST['is_active'])?1:0,$userId]);
 }elseif($action==='assign-worker-unit'){
  $pdo->prepare('INSERT INTO worker_business_units (worker_profile_id,business_unit_id,is_lead,assigned_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE is_lead=VALUES(is_lead),assigned_by=VALUES(assigned_by),assigned_at=UTC_TIMESTAMP(6),ends_at=NULL')->execute([(int)$_POST['worker_profile_id'],(int)$_POST['business_unit_id'],isset($_POST['is_lead'])?1:0,$userId]);
 }elseif($action==='assign-client-unit'){
  $pdo->prepare('INSERT INTO client_business_units (client_id,business_unit_id,assigned_by) VALUES (?,?,?) ON DUPLICATE KEY UPDATE assigned_by=VALUES(assigned_by),assigned_at=UTC_TIMESTAMP(6)')->execute([(int)$_POST['client_id'],(int)$_POST['business_unit_id'],$userId]);
 }elseif($action==='save-worker-scope'){
  $scope=(string)($_POST['access_scope']??'own');if(!in_array($scope,['own','assigned','business_unit','all'],true))throw new DomainException('Invalid worker access scope.');
  $capability=(string)($_POST['capability']??'');if(!in_array($capability,['clients.search','jobs.view','documents.create','documents.view','timekeeping.self','mileage.self','workforce.statements.self'],true))throw new DomainException('Invalid worker capability.');
  $pdo->prepare('INSERT INTO worker_capability_scopes (worker_profile_id,capability,access_scope,allowed,granted_by) VALUES (?,?,?,1,?) ON DUPLICATE KEY UPDATE access_scope=VALUES(access_scope),allowed=1,granted_by=VALUES(granted_by)')->execute([(int)$_POST['worker_profile_id'],$capability,$scope,$userId]);
 }elseif($action==='save-pay-schedule'){
  $cadence=(string)($_POST['cadence']??'biweekly');if(!in_array($cadence,['weekly','biweekly','semimonthly','monthly','custom'],true))throw new DomainException('Invalid cadence.');
  foreach(['workforce_pay_period_cadence'=>$cadence,'workforce_pay_period_anchor'=>trim((string)($_POST['anchor']??'')),'workforce_pay_period_custom_days'=>(string)max(1,min(366,(int)($_POST['custom_days']??14)))] as $key=>$value)$pdo->prepare('INSERT INTO app_config (organization_id,config_key,config_value) VALUES (0,?,?) ON DUPLICATE KEY UPDATE config_value=VALUES(config_value)')->execute([$key,$value]);
 }elseif($action==='assignment-offer'){
  (new JobWorkPlanningService($pdo,new CompensationRuleService($pdo)))->offer((int)$_POST['assignment_id'],(int)$_POST['worker_profile_id'],$userId);
 }elseif($action==='assignment-approve'){
  (new JobWorkPlanningService($pdo,new CompensationRuleService($pdo)))->approvePayable((int)$_POST['assignment_id'],$userId);
 }elseif($action==='assignment-eligible'){
  $trigger=(string)($_POST['trigger_event']??'completed_approved');
  if(!in_array($trigger,['completed_approved','delivered','manual_release'],true))throw new DomainException('This compensation trigger cannot be released manually.');
  (new JobWorkPlanningService($pdo,new CompensationRuleService($pdo)))->markEligible((int)$_POST['assignment_id'],['trigger_event'=>$trigger],$userId);
 }elseif($action==='close-pay-period'){
  $result=(new PayPeriodService($pdo))->close((int)$_POST['pay_period_id'],$userId,!empty($_POST['force']));
  if(!$result['closed'])throw new DomainException('Missing submissions: '.implode(' ',(array)$result['warnings']).' Review them, or use Close anyway.');
 }elseif($action==='settle-statement'){
  (new PayPeriodService($pdo))->settleStatement((int)$_POST['statement_id']);
 }else throw new DomainException('Unsupported workforce setting action.');
 header('Location: '.$redirect.'&saved=1');exit;
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();header('Location: '.$redirect.'&error='.rawurlencode($e instanceof DomainException?$e->getMessage():'Unable to save workforce settings.'));exit;}

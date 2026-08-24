<?php
declare(strict_types=1);

use App\Domain\Pricing\PricingAdjustmentManager;

require_once __DIR__.'/../../config/db.php';
require_once __DIR__.'/../../utils/csrf.php';
require_once __DIR__.'/../../utils/acl.php';
require_once __DIR__.'/../../utils/document_pricing_adjustments.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$redirect='/?page=settings&tab=pricing-adjustments';
$actor=(int)($_SESSION['user']['id']??0);
if(($_SERVER['REQUEST_METHOD']??'')!=='POST'||!csrf_validate()||$actor<1||!user_can($pdo,$actor,'financial.manage',0)){
    http_response_code(403);header('Location: '.$redirect.'&error='.rawurlencode('The request could not be authorized.'));exit;
}
if(!pricing_adjustments_enabled($pdo)||!pricing_adjustment_schema_available($pdo)){
    http_response_code(409);header('Location: '.$redirect.'&error='.rawurlencode('Pricing adjustments are not available on this installation.'));exit;
}

try{
    $manager=new PricingAdjustmentManager($pdo,$actor);
    $action=(string)($_POST['action']??'');
    $name=trim((string)($_POST['name']??''));
    $rate=trim((string)($_POST['percentage_rate']??''));
    $from=trim((string)($_POST['effective_from']??''))?:null;
    $until=trim((string)($_POST['effective_until']??''))?:null;
    if($action==='create'){
        $scope=(string)($_POST['scope_type']??'installation');
        if($scope==='installation')$manager->createInstallationDefinition($name,$rate,$from,$until);
        elseif($scope==='customer')$manager->createDefinition((int)($_POST['organization_id']??0),$name,$rate,$from,$until);
        else throw new DomainException('Invalid pricing adjustment scope.');
    }elseif($action==='update'){
        $manager->updateDefinition((int)($_POST['definition_id']??0),$name,$rate,$from,$until);
    }elseif($action==='deactivate'){
        $manager->deactivateDefinition((int)($_POST['definition_id']??0));
    }elseif($action==='assign-project'){
        $manager->assignProject((int)($_POST['organization_id']??0),(int)($_POST['target_id']??0),(int)($_POST['definition_id']??0));
    }elseif($action==='unassign-project'){
        $manager->unassignProject((int)($_POST['organization_id']??0),(int)($_POST['target_id']??0));
    }elseif($action==='assign-contract'){
        $manager->assignContract((int)($_POST['organization_id']??0),(int)($_POST['target_id']??0),(int)($_POST['definition_id']??0));
    }elseif($action==='unassign-contract'){
        $manager->unassignContract((int)($_POST['organization_id']??0),(int)($_POST['target_id']??0));
    }else throw new DomainException('Unsupported pricing adjustment action.');
    $return=(string)($_POST['return_to']??$redirect);
    if(strlen($return)>2048||str_contains($return,"\r")||str_contains($return,"\n")||!str_starts_with($return,'/?page='))$return=$redirect;
    header('Location: '.$return.(str_contains($return,'?')?'&':'?').'saved=1');exit;
}catch(DomainException $error){
    $return=(string)($_POST['return_to']??$redirect);
    if(strlen($return)>2048||str_contains($return,"\r")||str_contains($return,"\n")||!str_starts_with($return,'/?page='))$return=$redirect;
    header('Location: '.$return.(str_contains($return,'?')?'&':'?').'error='.rawurlencode($error->getMessage()));exit;
}catch(Throwable $error){
    error_log('Pricing adjustment settings update failed: '.$error->getMessage());
    $return=(string)($_POST['return_to']??$redirect);
    if(strlen($return)>2048||str_contains($return,"\r")||str_contains($return,"\n")||!str_starts_with($return,'/?page='))$return=$redirect;
    header('Location: '.$return.(str_contains($return,'?')?'&':'?').'error='.rawurlencode('Unable to update pricing adjustments.'));exit;
}

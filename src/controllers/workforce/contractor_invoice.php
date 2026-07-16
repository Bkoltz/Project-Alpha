<?php

declare(strict_types=1);

require_once __DIR__.'/../../config/db.php';require_once __DIR__.'/../../utils/acl.php';require_once __DIR__.'/../../utils/csrf.php';require_once __DIR__.'/../../utils/audit.php';require_once __DIR__.'/../../utils/upload_validator.php';
$actorId=(int)($_SESSION['user']['id']??0);if($actorId<=0||($_SERVER['REQUEST_METHOD']??'')!=='POST'||!csrf_validate()){http_response_code(403);exit('Forbidden');}
try{$statementId=(int)($_POST['statement_id']??0);$stmt=$pdo->prepare('SELECT ws.*,wp.user_id FROM worker_statements ws JOIN worker_profiles wp ON wp.id=ws.worker_profile_id WHERE ws.id=? AND ws.statement_type="contractor_settlement"');$stmt->execute([$statementId]);$statement=$stmt->fetch(PDO::FETCH_ASSOC);if(!$statement)throw new DomainException('Contractor statement not found.');
$manage=in_array((string)($_SESSION['user']['role']??''),['admin','owner'],true)||user_can($pdo,$actorId,'workforce.statements.manage',0);if(!$manage&&(int)$statement['user_id']!==$actorId)throw new DomainException('You cannot attach an invoice to this statement.');if(!isset($_FILES['contractor_invoice']))throw new DomainException('Choose a PDF invoice.');
if(!empty($statement['contractor_invoice_path']))throw new DomainException('This statement already has a contractor invoice attached.');
$dir=dirname(__DIR__,2).'/uploads/contractor-invoices/worker-'.$statement['worker_profile_id'];$error=null;$stored=validate_and_store_upload($_FILES['contractor_invoice'],['application/pdf'=>'pdf'],15*1024*1024,$dir,$error,['require_pdf_header'=>true,'reject_pdf_active_content'=>true]);if($stored===null)throw new DomainException($error?:'Invoice upload failed.');$absolute=$dir.DIRECTORY_SEPARATOR.$stored;$relative='contractor-invoices/worker-'.$statement['worker_profile_id'].'/'.$stored;
$pdo->prepare('UPDATE worker_statements SET contractor_invoice_path=?,contractor_invoice_sha256=? WHERE id=?')->execute([$relative,hash_file('sha256',$absolute),$statementId]);audit_log($pdo,'contractor_invoice.attached','worker_statement',$statementId,['worker_profile_id'=>(int)$statement['worker_profile_id']]);header('Location: /?page=workforce/pay&success='.rawurlencode('Contractor invoice attached.'));exit;
}catch(Throwable $e){header('Location: /?page=workforce/pay&error='.rawurlencode($e instanceof DomainException?$e->getMessage():'Unable to attach contractor invoice.'));exit;}

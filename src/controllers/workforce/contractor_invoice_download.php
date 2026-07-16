<?php

declare(strict_types=1);

require_once __DIR__.'/../../config/db.php';require_once __DIR__.'/../../utils/acl.php';
$actorId=(int)($_SESSION['user']['id']??0);$statementId=(int)($_GET['id']??0);if($actorId<=0||$statementId<=0){http_response_code(404);exit;}
$stmt=$pdo->prepare('SELECT ws.contractor_invoice_path,wp.user_id FROM worker_statements ws JOIN worker_profiles wp ON wp.id=ws.worker_profile_id WHERE ws.id=?');$stmt->execute([$statementId]);$row=$stmt->fetch(PDO::FETCH_ASSOC);$manage=in_array((string)($_SESSION['user']['role']??''),['admin','owner'],true)||user_can($pdo,$actorId,'workforce.statements.manage',0);if(!$row||(!$manage&&(int)$row['user_id']!==$actorId)){http_response_code(403);exit('Forbidden');}
$root=realpath(dirname(__DIR__,2).'/uploads/contractor-invoices');$path=$root!==false?realpath(dirname(__DIR__,2).'/uploads/'.ltrim(str_replace(['/', '\\'],DIRECTORY_SEPARATOR,(string)$row['contractor_invoice_path']),DIRECTORY_SEPARATOR)):false;if($root===false||$path===false||!is_file($path)||!str_starts_with($path,rtrim($root,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)){http_response_code(404);exit('Invoice not found.');}
header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="contractor-invoice-'.$statementId.'.pdf"');header('Cache-Control: private, no-store');header('Content-Length: '.filesize($path));readfile($path);exit;

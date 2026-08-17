<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/acl.php';

$publicId=(string)($_GET['_quote_public_id']??'');
$statement=$pdo->prepare('SELECT id FROM quotes WHERE public_id=?');
$statement->execute([$publicId]);
$quoteId=(int)$statement->fetchColumn();
if($quoteId<1){http_response_code(404);echo'<div class="alert alert-danger">Quote not found.</div>';return;}
try{require_record_ownership($pdo,'quotes',$quoteId);}catch(Throwable){http_response_code(404);echo'<div class="alert alert-danger">Quote not found.</div>';return;}
$_GET['id']=$quoteId;
require __DIR__ . '/quotes-edit.php';

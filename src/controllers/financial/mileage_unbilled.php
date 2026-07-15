<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
header('Content-Type: application/json');
$userId=(int)($_SESSION['user']['id']??0);$clientId=max(0,(int)($_GET['client_id']??0));
if($userId<=0){http_response_code(401);echo json_encode(['error'=>'Unauthorized']);exit;}
if($clientId<=0){http_response_code(400);echo json_encode(['error'=>'Choose a client first.']);exit;}
if(!user_can($pdo,$userId,'invoices.create',0)){http_response_code(403);echo json_encode(['error'=>'Permission denied.']);exit;}
try{
 $stmt=$pdo->prepare('SELECT a.id,a.mileage_log_id,a.charge_method,a.billable_miles,a.mileage_rate,a.fixed_amount,a.client_charge,
   m.trip_date,m.start_location,m.end_location,m.logged_miles,m.description,
   CASE WHEN a.charge_method="fixed_fee" THEN 1 ELSE a.billable_miles END quantity,
   CASE WHEN a.charge_method="fixed_fee" THEN a.client_charge ELSE a.mileage_rate END unit_price,
   CASE WHEN a.charge_method="fixed_fee" THEN "each" ELSE "mile" END billing_unit,
   p.name project_name
   FROM mileage_charge_allocations a JOIN mileage_logs m ON m.id=a.mileage_log_id
   LEFT JOIN projects p ON p.id=a.project_id
   WHERE a.client_id=? AND a.billed=0 ORDER BY m.trip_date,m.id,a.id');
 $stmt->execute([$clientId]);echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC),JSON_NUMERIC_CHECK);
}catch(Throwable $e){error_log('[MileageUnbilled] '.$e->getMessage());http_response_code(500);echo json_encode(['error'=>'Client travel charges are not ready. Apply the latest migration.']);}
exit;

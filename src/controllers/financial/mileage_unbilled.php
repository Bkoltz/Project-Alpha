<?php

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

header('Content-Type: application/json');

$userId = (int)($_SESSION['user']['id'] ?? 0);
$clientId = max(0, (int)($_GET['client_id'] ?? 0));
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}
if ($clientId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Choose a client first.']);
    exit;
}
if (!user_can($pdo, $userId, 'invoices.create', 0)) {
    http_response_code(403);
    echo json_encode(['error' => 'Permission denied.']);
    exit;
}

try {
    $stmt = $pdo->prepare(
        'SELECT m.id,m.trip_date,m.start_location,m.end_location,m.description,m.round_trip,m.bill_return_trip,m.mileage_rate,
                (m.miles * CASE WHEN m.round_trip=1 THEN 2 ELSE 1 END) logged_quantity,
                (m.miles * CASE WHEN m.round_trip=1 AND m.bill_return_trip=1 THEN 2 ELSE 1 END) quantity,
                (m.miles * CASE WHEN m.round_trip=1 AND m.bill_return_trip=1 THEN 2 ELSE 1 END * m.mileage_rate) amount,
                p.name project_name
         FROM mileage_logs m
         LEFT JOIN projects p ON p.id=m.project_id
         WHERE m.client_id=? AND m.is_billable=1 AND m.billed=0
         ORDER BY m.trip_date,m.id'
    );
    $stmt->execute([$clientId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC), JSON_NUMERIC_CHECK);
} catch (Throwable $e) {
    @error_log('[MileageUnbilled] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Billable mileage storage is not ready. Apply the latest migration.']);
}
exit;

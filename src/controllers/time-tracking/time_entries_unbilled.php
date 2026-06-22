<?php
// src/controllers/time-tracking/time_entries_unbilled.php
// AJAX endpoint returning unbilled billable time entries as JSON
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../../../utils/csrf.php';

$userId = (int)($_SESSION['user']['id'] ?? 0);
$clientId = (int)($_GET['client_id'] ?? 0);

header('Content-Type: application/json');

if ($userId === 0) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$params = [$userId, 1, 0];
$sql = 'SELECT te.id, te.started_at, te.description, te.hours, te.rate, (te.hours * te.rate) AS amount, c.name AS client_name FROM time_entries te LEFT JOIN clients c ON te.client_id = c.id WHERE te.user_id = ? AND te.billable = ? AND te.billed = ?';
if ($clientId > 0) {
    $sql .= ' AND te.client_id = ?';
    $params[] = $clientId;
}
$sql .= ' ORDER BY te.started_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
exit;

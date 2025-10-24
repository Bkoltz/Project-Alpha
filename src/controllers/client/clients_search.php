<?php
// src/controllers/client/clients_search.php
require_once __DIR__ . '/../../config/db.php';
header('Content-Type: application/json');
$term = trim((string)($_GET['term'] ?? ''));
if ($term === '') { echo json_encode([]); exit; }
$hasArchived = (bool)$pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='archived'")->fetchColumn();
$sql = "SELECT id, name FROM clients ".($hasArchived?"WHERE archived=0 AND ":"WHERE ")."name LIKE ? ORDER BY name LIMIT 10";
$stmt = $pdo->prepare($sql);
$stmt->execute(['%'.$term.'%']);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
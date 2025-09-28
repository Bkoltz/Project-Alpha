<?php
// src/controllers/clients_search.php
require_once __DIR__ . '/../config/db.php';
header('Content-Type: application/json');
$term = trim((string)($_GET['term'] ?? ''));
if ($term === '') { echo json_encode([]); exit; }
$sql = "SELECT id, name FROM clients WHERE name LIKE ? ORDER BY name LIMIT 10";
$stmt = $pdo->prepare($sql);
$stmt->execute(['%'.$term.'%']);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

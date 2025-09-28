<?php
// src/controllers/contract_deny.php
require_once __DIR__ . '/../config/db.php';
$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) { header('Location: /?page=contracts-list&error=Invalid%20contract'); exit; }
$pdo->prepare('UPDATE contracts SET status="cancelled" WHERE id=?')->execute([$id]);
header('Location: /?page=contracts-list&denied=1');
exit;
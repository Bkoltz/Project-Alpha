<?php
// src/controllers/clients_restore.php
require_once __DIR__ . '/../config/db.php';

$id = (int)($_POST['id'] ?? 0); // archived_clients.id
if ($id <= 0) {
  header('Location: /?page=archived-clients&error=Invalid%20request');
  exit;
}

$ac = $pdo->prepare('SELECT * FROM archived_clients WHERE id=?');
$ac->execute([$id]);
$row = $ac->fetch(PDO::FETCH_ASSOC);
if (!$row) {
  header('Location: /?page=archived-clients&error=Not%20found');
  exit;
}

$pdo->beginTransaction();
try {
  // Attempt to restore the client row. If original client_id is free, use it; otherwise create new.
  $origId = (int)$row['client_id'];
  $exists = $pdo->prepare('SELECT COUNT(*) FROM clients WHERE id=?');
  $exists->execute([$origId]);
  $useOrig = $origId > 0 && (int)$exists->fetchColumn() === 0;

  if ($useOrig) {
    $ins = $pdo->prepare('INSERT INTO clients (id,name,email,phone,organization,notes,address_line1,address_line2,city,state,postal,country,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([$origId, $row['name'], $row['email'], $row['phone'], $row['organization'], $row['notes'], $row['address_line1'], $row['address_line2'], $row['city'], $row['state'], $row['postal'], $row['country'], $row['created_at']]);
  } else {
    $ins = $pdo->prepare('INSERT INTO clients (name,email,phone,organization,notes,address_line1,address_line2,city,state,postal,country,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([$row['name'], $row['email'], $row['phone'], $row['organization'], $row['notes'], $row['address_line1'], $row['address_line2'], $row['city'], $row['state'], $row['postal'], $row['country'], $row['created_at']]);
  }

  // Remove archive record (keep archived_entities for historical record)
  $pdo->prepare('DELETE FROM archived_clients WHERE id=?')->execute([$id]);

  $pdo->commit();
  header('Location: /?page=clients-list&restored=1');
  exit;
} catch (Throwable $e) {
  $pdo->rollBack();
  header('Location: /?page=archived-clients&error=Restore%20failed');
  exit;
}

<?php
// src/controllers/clients_delete.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=client/clients-list&error=Invalid%20client');
  exit;
}

// Fetch client
$st = $pdo->prepare('SELECT * FROM clients WHERE id=?');
$st->execute([$id]);
$client = $st->fetch(PDO::FETCH_ASSOC);
if (!$client) {
  header('Location: /?page=client/clients-list&error=Client%20not%20found');
  exit;
}

$pdo->beginTransaction();
try {
  // 1) Archive client basic row
  $insC = $pdo->prepare('INSERT INTO archived_clients (client_id,name,email,phone,organization,notes,address_line1,address_line2,city,state,postal,country,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $insC->execute([
    $client['id'], $client['name'], $client['email'] ?? null, $client['phone'] ?? null, $client['organization'] ?? null,
    $client['notes'] ?? null, $client['address_line1'] ?? null, $client['address_line2'] ?? null, $client['city'] ?? null,
    $client['state'] ?? null, $client['postal'] ?? null, $client['country'] ?? null, $client['created_at'] ?? null
  ]);

  // Helper to archive a set
  $arch = $pdo->prepare('INSERT INTO archived_entities (client_id, entity_type, entity_id, payload) VALUES (?,?,?,JSON_OBJECT())');
  // We will build payloads manually to avoid JSON encoding issues in SQL; instead use PHP JSON.
  $arch = $pdo->prepare('INSERT INTO archived_entities (client_id, entity_type, entity_id, payload) VALUES (?,?,?,?)');

  // 2) Quotes and items
  $quotes = $pdo->prepare('SELECT * FROM quotes WHERE client_id=?');
  $quotes->execute([$id]);
  $quotes = $quotes->fetchAll(PDO::FETCH_ASSOC);
  foreach ($quotes as $q) {
    $arch->execute([$id, 'quote', (int)$q['id'], json_encode($q, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $qi = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id=?');
    $qi->execute([$q['id']]);
    foreach ($qi->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $arch->execute([$id, 'quote_item', (int)$row['id'], json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
  }

  // 3) Contracts and items
  $contracts = $pdo->prepare('SELECT * FROM contracts WHERE client_id=?');
  $contracts->execute([$id]);
  foreach ($contracts->fetchAll(PDO::FETCH_ASSOC) as $co) {
    $arch->execute([$id, 'contract', (int)$co['id'], json_encode($co, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $ci = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id=?');
    $ci->execute([$co['id']]);
    foreach ($ci->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $arch->execute([$id, 'contract_item', (int)$row['id'], json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
  }

  // 4) Invoices and items
  $invoices = $pdo->prepare('SELECT * FROM invoices WHERE client_id=?');
  $invoices->execute([$id]);
  foreach ($invoices->fetchAll(PDO::FETCH_ASSOC) as $inv) {
    $arch->execute([$id, 'invoice', (int)$inv['id'], json_encode($inv, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $ii = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id=?');
    $ii->execute([$inv['id']]);
    foreach ($ii->fetchAll(PDO::FETCH_ASSOC) as $row) {
      $arch->execute([$id, 'invoice_item', (int)$row['id'], json_encode($row, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    }
  }

  // 5) Payments (by joining invoices for this client)
  $pay = $pdo->prepare('SELECT p.* FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE i.client_id=?');
  $pay->execute([$id]);
  foreach ($pay->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $arch->execute([$id, 'payment', (int)$p['id'], json_encode($p, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
  }

  // 6) Delete client (will cascade to related tables based on FKs in schema)
  $pdo->prepare('DELETE FROM clients WHERE id=?')->execute([$id]);

  $pdo->commit();
  header('Location: /?page=client/clients-list&archived=1');
  exit;
} catch (Throwable $e) {
  $pdo->rollBack();
  header('Location: /?page=client/clients-edit&id='.$id.'&error=Archive%20failed&details='.urlencode($e->getMessage()));
  exit;
}

<?php
// src/controllers/client/clients_delete.php
// Updated: uses soft delete instead of archived_clients table
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=client/clients-list&error=Invalid%20client');
  exit;
}

// Fetch client
$st = $pdo->prepare('SELECT * FROM clients WHERE id=? AND deleted_at IS NULL');
$st->execute([$id]);
$client = $st->fetch(PDO::FETCH_ASSOC);
if (!$client) {
  header('Location: /?page=client/clients-list&error=Client%20not%20found');
  exit;
}

// Build archive payload with related entities
$archivePayload = [
  'client' => $client,
  'quotes' => [],
  'contracts' => [],
  'invoices' => [],
  'payments' => []
];

// Fetch related quotes
$quotes = $pdo->prepare('SELECT * FROM quotes WHERE client_id=?');
$quotes->execute([$id]);
$archivePayload['quotes'] = $quotes->fetchAll(PDO::FETCH_ASSOC);

// Fetch quote items
foreach ($archivePayload['quotes'] as &$q) {
  $qi = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id=?');
  $qi->execute([$q['id']]);
  $q['items'] = $qi->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch contracts (unified table)
$contracts = $pdo->prepare('SELECT * FROM contracts WHERE client_id=?');
$contracts->execute([$id]);
$archivePayload['contracts'] = $contracts->fetchAll(PDO::FETCH_ASSOC);

// Fetch contract items
foreach ($archivePayload['contracts'] as &$c) {
  $ci = $pdo->prepare('SELECT * FROM contract_items WHERE contract_id=?');
  $ci->execute([$c['id']]);
  $c['items'] = $ci->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch invoices
$invoices = $pdo->prepare('SELECT * FROM invoices WHERE client_id=?');
$invoices->execute([$id]);
$archivePayload['invoices'] = $invoices->fetchAll(PDO::FETCH_ASSOC);

// Fetch invoice items
foreach ($archivePayload['invoices'] as &$inv) {
  $ii = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id=?');
  $ii->execute([$inv['id']]);
  $inv['items'] = $ii->fetchAll(PDO::FETCH_ASSOC);
}

// Fetch payments via invoices
$pay = $pdo->prepare('SELECT p.* FROM payments p JOIN invoices i ON i.id=p.invoice_id WHERE i.client_id=?');
$pay->execute([$id]);
$archivePayload['payments'] = $pay->fetchAll(PDO::FETCH_ASSOC);

// Soft delete: set deleted_at and archive_payload
$update = $pdo->prepare('UPDATE clients SET deleted_at = NOW(), archive_payload = ? WHERE id = ?');
$update->execute([
  json_encode($archivePayload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
  $id
]);

header('Location: /?page=client/clients-list&archived=1');
exit;

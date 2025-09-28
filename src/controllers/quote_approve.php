<?php
// src/controllers/quote_approve.php
require_once __DIR__ . '/../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: /?page=quotes-list&error=Invalid%20quote');
  exit;
}

$pdo->beginTransaction();
try {
  // Load quote + items
  $q = $pdo->prepare('SELECT * FROM quotes WHERE id=? FOR UPDATE');
  $q->execute([$id]);
  $quote = $q->fetch(PDO::FETCH_ASSOC);
  if (!$quote) throw new Exception('Quote not found');
  if ($quote['status'] !== 'pending') throw new Exception('Quote not pending');
  $items = $pdo->prepare('SELECT * FROM quote_items WHERE quote_id=?');
  $items->execute([$id]);
  $qitems = $items->fetchAll(PDO::FETCH_ASSOC);

  // Mark quote approved
  $pdo->prepare('UPDATE quotes SET status="approved" WHERE id=?')->execute([$id]);

  // Create contract
  $pdo->prepare('INSERT INTO contracts (quote_id, client_id, status) VALUES (?,?,?)')
      ->execute([$id, (int)$quote['client_id'], 'active']);
  $contract_id = (int)$pdo->lastInsertId();
  $ci = $pdo->prepare('INSERT INTO contract_items (contract_id, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?)');
  foreach ($qitems as $it) {
    $ci->execute([$contract_id, $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
  }

  // Do not create invoice here anymore; invoice will be created when contract is signed
  // (see contract_sign controller)

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  header('Location: /?page=quotes-list&error=' . urlencode('Failed to approve: ' . $e->getMessage()));
  exit;
}

header('Location: /?page=quotes-list&approved=1');
exit;

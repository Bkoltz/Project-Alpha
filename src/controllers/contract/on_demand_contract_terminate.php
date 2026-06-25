<?php
// src/controllers/contract/on_demand_contract_terminate.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/on-demand-contracts-list&error=Invalid%20ID');
    exit;
}
require_record_ownership($pdo, 'contracts', $id);

try {
    $pdo->prepare('UPDATE contracts SET status=? WHERE id=? AND contract_type="on_demand"')->execute(['cancelled', $id]);
    header('Location: /?page=contract/on-demand-contracts-list');
} catch (Throwable $e) {
    header('Location: /?page=contract/on-demand-contracts-list&error=' . urlencode($e->getMessage()));
}
exit;

<?php
// src/controllers/contract/on_demand_contract_activate.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../config/app.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/on-demand-contracts-list&error=Invalid%20ID');
    exit;
}

try {
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="on_demand"');
    $stmt->execute([$id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$contract) {
        throw new Exception('Contract not found');
    }

    if ($contract['status'] !== 'pending') {
        throw new Exception('Only pending contracts can be activated');
    }

    if (empty($contract['signed_pdf_path'])) {
        throw new Exception('Upload signed contract first');
    }

    $pdo->prepare('UPDATE contracts SET status=? WHERE id=? AND contract_type="on_demand"')->execute(['active', $id]);
    header('Location: /?page=contract/on-demand-contracts-list&activated=1');
} catch (Throwable $e) {
    header('Location: /?page=contract/on-demand-contracts-list&error=' . urlencode($e->getMessage()));
}
exit;

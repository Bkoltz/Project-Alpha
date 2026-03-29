<?php
// src/controllers/contract/contract_deposit_received.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/contracts-list&error=Invalid%20contract');
    exit;
}

$pdo->beginTransaction();
try {
    // Get contract info
    $stmt = $pdo->prepare('SELECT deposit_type, deposit_amount, total, deposit_paid FROM contracts WHERE id=? FOR UPDATE');
    $stmt->execute([$id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        throw new Exception('Contract not found');
    }
    
    // Calculate deposit amount
    $depositType = $contract['deposit_type'] ?? 'none';
    $depositValue = (float)($contract['deposit_amount'] ?? 0);
    $total = (float)($contract['total'] ?? 0);
    $depositCalc = 0;
    
    if ($depositType === 'percent') {
        $depositCalc = max(0, min(100, $depositValue)) * $total / 100;
    } elseif ($depositType === 'fixed') {
        $depositCalc = $depositValue;
    }
    
    if ($depositCalc <= 0) {
        throw new Exception('No deposit required for this contract');
    }
    
    // Mark deposit as paid
    $pdo->prepare('UPDATE contracts SET deposit_paid=? WHERE id=?')
        ->execute([$depositCalc, $id]);
    
    // Update linked invoices: reduce total by deposit amount and mark as partial
    $pdo->prepare('UPDATE invoices SET total = total - ?, status = "partial" WHERE contract_id = ? AND status = "unpaid"')
        ->execute([$depositCalc, $id]);
    
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    header('Location: /?page=contract/contracts-list&error=' . urlencode($e->getMessage()));
    exit;
}

header('Location: /?page=contract/contracts-list&deposit_received=1');
exit;

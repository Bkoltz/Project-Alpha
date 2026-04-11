<?php
// src/controllers/contract/long_term_contract_terminate.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/long-term-contracts-list&error=Invalid%20contract%20ID');
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Get contract details
    $stmt = $pdo->prepare('SELECT * FROM long_term_contracts WHERE id=?');
    $stmt->execute([$id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        throw new Exception('Contract not found');
    }
    
    if ($contract['status'] === 'completed' || $contract['status'] === 'cancelled') {
        throw new Exception('Contract is already terminated');
    }
    
    // Update contract status to completed and set end_date to today if not set
    $endDate = $contract['end_date'] ?: date('Y-m-d');
    $update = $pdo->prepare('UPDATE long_term_contracts SET status=?, end_date=?, next_invoice_date=NULL WHERE id=?');
    $update->execute(['completed', $endDate, $id]);
    
    $pdo->commit();
    
    header('Location: /?page=contract/long-term-contracts-list&terminated=1');
    exit;
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[long_term_contract_terminate] Error: ' . $e->getMessage());
    header('Location: /?page=contract/long-term-contracts-list&error=' . urlencode($e->getMessage()));
    exit;
}

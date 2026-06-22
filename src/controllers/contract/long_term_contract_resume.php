<?php
// src/controllers/contract/long_term_contract_resume.php
require_once __DIR__ . '/../../config/db.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/long-term-contracts-list&error=Invalid%20contract%20ID');
    exit;
}

try {
    $pdo->beginTransaction();
    
    // Get contract details
    $stmt = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term"');
    $stmt->execute([$id]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        throw new Exception('Contract not found');
    }
    
    if ($contract['status'] !== 'paused') {
        throw new Exception('Only paused contracts can be resumed');
    }
    
    // Update contract status to active
    $update = $pdo->prepare('UPDATE contracts SET status=? WHERE id=? AND contract_type="long_term"');
    $update->execute(['active', $id]);
    
    $pdo->commit();
    
    header('Location: /?page=contract/long-term-contracts-list&resumed=1');
    exit;
    
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    @error_log('[long_term_contract_resume] Error: ' . $e->getMessage());
    header('Location: /?page=contract/long-term-contracts-list&error=' . urlencode($e->getMessage()));
    exit;
}

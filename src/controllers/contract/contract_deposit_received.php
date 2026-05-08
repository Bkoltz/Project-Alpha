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
    
    // The deposit_amount field stores the ALREADY CALCULATED deposit amount
    // (calculated at contract creation based on deposit_type and deposit_value)
    // So we just use it directly, no need to recalculate
    $depositType = $contract['deposit_type'] ?? 'none';
    $depositCalc = (float)($contract['deposit_amount'] ?? 0);
    
    if ($depositType === 'none' || $depositCalc <= 0) {
        throw new Exception('No deposit required for this contract');
    }
    
    // Check if already paid
    $alreadyPaid = (float)($contract['deposit_paid'] ?? 0);
    if ($alreadyPaid >= $depositCalc) {
        throw new Exception('Deposit has already been received');
    }
    
    // Mark deposit as paid on contract
    $pdo->prepare('UPDATE contracts SET deposit_paid=? WHERE id=?')
        ->execute([$depositCalc, $id]);
    
    // Get the linked invoice and record the deposit as a payment
    $invStmt = $pdo->prepare('SELECT id, total FROM invoices WHERE contract_id = ? LIMIT 1');
    $invStmt->execute([$id]);
    $linkedInvoice = $invStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($linkedInvoice) {
        $linkedInvoiceId = (int)$linkedInvoice['id'];
        $invoiceTotal = (float)$linkedInvoice['total'];
        
        // Record deposit as a succeeded payment so it counts toward amount paid
        $pdo->prepare('INSERT INTO payments (invoice_id, amount, method, status, reference_number) VALUES (?, ?, ?, ?, ?)')
            ->execute([$linkedInvoiceId, $depositCalc, 'deposit', 'succeeded', 'Contract Deposit']);
        
        // Calculate total paid on this invoice
        $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount), 0) FROM payments WHERE invoice_id = ? AND status = "succeeded"');
        $paidStmt->execute([$linkedInvoiceId]);
        $totalPaid = (float)$paidStmt->fetchColumn();
        
        // Update invoice status (and amount_paid if column exists)
        $newStatus = ($totalPaid >= $invoiceTotal) ? 'paid' : 'partial';
        try {
            $pdo->prepare('UPDATE invoices SET amount_paid = ?, status = ? WHERE id = ?')
                ->execute([$totalPaid, $newStatus, $linkedInvoiceId]);
        } catch (Throwable $e) {
            // amount_paid column might not exist, just update status
            $pdo->prepare('UPDATE invoices SET status = ? WHERE id = ?')
                ->execute([$newStatus, $linkedInvoiceId]);
        }
    }
    
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

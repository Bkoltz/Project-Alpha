<?php
// src/controllers/contract/contract_deposit_received.php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/acl.php';
require_once __DIR__ . '/../../utils/invoice_lifecycle.php';

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: /?page=contract/contracts-list&error=Invalid%20contract');
    exit;
}

require_record_ownership($pdo, 'contracts', $id);

$pdo->beginTransaction();
try {
    // Get contract info
    $stmt = $pdo->prepare('SELECT id, client_id, organization_id, deposit_type, deposit_amount, total, deposit_paid FROM contracts WHERE id=? FOR UPDATE');
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
    
    $alreadyPaid = (float)($contract['deposit_paid'] ?? 0);
    if ($alreadyPaid >= $depositCalc) {
        throw new Exception('Deposit has already been received');
    }
    $depositRemaining = max(0.0, $depositCalc - $alreadyPaid);
    
    // Get the linked direct-collection invoice and record the deposit as a payment.
    // Long-term/project aggregate invoices are handled by their own invoice/payment flows.
    $invStmt = $pdo->prepare('
        SELECT id, total, collection_mode, finalized_at
        FROM invoices
        WHERE contract_id = ?
        ORDER BY id ASC
        LIMIT 1
    ');
    $invStmt->execute([$id]);
    $linkedInvoice = $invStmt->fetch(PDO::FETCH_ASSOC);
    
    if ($linkedInvoice) {
        $linkedInvoiceId = (int)$linkedInvoice['id'];
        $outstanding = max(0.0, (float)$linkedInvoice['total'] - invoice_effective_paid_total($pdo, $linkedInvoiceId));
        if ($depositRemaining > $outstanding + 0.005) {
            throw new Exception('Deposit exceeds the outstanding invoice balance');
        }

        invoice_record_locked_payment(
            $pdo,
            $linkedInvoiceId,
            $depositRemaining,
            'other',
            'Contract Deposit',
            'Contract deposit received',
            [
                'organization_id' => !empty($contract['organization_id']) ? (int)$contract['organization_id'] : null,
                'allow_unfinalized' => true,
                'source' => 'contract_deposit',
            ]
        );
    }

    // Mark deposit as paid only after the linked payment, if any, was recorded safely.
    $pdo->prepare('UPDATE contracts SET deposit_paid=? WHERE id=?')
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

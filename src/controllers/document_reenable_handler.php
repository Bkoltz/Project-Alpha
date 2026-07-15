<?php
// src/controllers/document_reenable_handler.php
// Re-enable (un-void) voided/cancelled documents and restore related documents

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../utils/invoice_lifecycle.php';

// CSRF is already verified by the router (index.php)
// No need to verify again here

$type = $_POST['type'] ?? '';
$id = (int)($_POST['id'] ?? 0);

if ($id <= 0 || !in_array($type, ['quote', 'contract', 'invoice', 'long_term_contract', 'on_demand_contract'])) {
    header('Location: /?page=dashboard&error=Invalid%20document%20type%20or%20ID');
    exit;
}

$pdo->beginTransaction();
try {
    switch ($type) {
        case 'quote':
            throw new Exception('Terminal quotes cannot be reopened. Clone the quote into a new draft.');

        case 'contract':
            // Re-enable contract (change from cancelled/denied/void back to pending)
            $st = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
            $st->execute([$id]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new Exception('Contract not found');
            
            // Only allow re-enabling cancelled/denied contracts
            if (!in_array($doc['status'], ['cancelled', 'denied', 'void'])) {
                throw new Exception('Only cancelled/denied/void contracts can be re-enabled');
            }
            
            // Store previous status to potentially restore related docs
            $previousStatus = $doc['status'];
            
            // Update status to pending, clear voided_at, and refresh document_date
            $pdo->prepare("UPDATE contracts SET status='pending', voided_at=NULL, document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$id]);
            
            // Re-enable related invoices that were voided when contract was voided
            $pdo->prepare("UPDATE invoices SET status='unpaid', document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE contract_id=? AND status='void'")
                ->execute([$id]);
            
            // Un-revoke public links for those invoices
            try {
                $pdo->prepare('UPDATE public_links SET revoked=0, redirect=NULL WHERE document_type="invoice" AND document_id IN (SELECT id FROM invoices WHERE contract_id=?) AND revoked=1')
                    ->execute([$id]);
            } catch (Throwable $_e) { /* ignore */ }
            
            $redirectPage = ($doc['contract_type'] ?? '') === 'long_term' ? 'contract/long-term-contract-details' : 'contract/contract-details';
            break;

        case 'invoice':
            // Backward-compatible route; invoice pages use invoice/invoice-reenable.
            invoice_reenable_void($pdo, $id);
            $redirectPage = 'invoice/invoice-details';
            break;

        case 'long_term_contract':
            // Re-enable long-term contract
            $st = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term" FOR UPDATE');
            $st->execute([$id]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new Exception('Long-term contract not found');
            
            // Only allow re-enabling cancelled/denied/void contracts
            if (!in_array($doc['status'], ['cancelled', 'denied', 'void'], true)) {
                throw new Exception('Only cancelled/denied/void long-term contracts can be re-enabled');
            }
            
            // Update status to pending and refresh document date
            $pdo->prepare("UPDATE contracts SET status='pending', voided_at=NULL, document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=? AND contract_type='long_term'")
                ->execute([$id]);
            
            $redirectPage = 'contract/long-term-contract-details';
            break;

        case 'on_demand_contract':
            // Re-enable on-demand contract
            $st = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="on_demand" FOR UPDATE');
            $st->execute([$id]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new Exception('On-demand contract not found');
            
            // Only allow re-enabling cancelled/denied/void contracts
            if (!in_array($doc['status'], ['cancelled', 'denied', 'void'], true)) {
                throw new Exception('Only cancelled/denied/void on-demand contracts can be re-enabled');
            }
            
            // Update status to pending and refresh document date
            $pdo->prepare("UPDATE contracts SET status='pending', voided_at=NULL, document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=? AND contract_type='on_demand'")
                ->execute([$id]);
            
            $redirectPage = 'contract/contract-details';
            break;

        default:
            throw new Exception('Invalid document type');
    }

    $pdo->commit();
    header("Location: /?page={$redirectPage}&id={$id}&reenabled=1");
    exit;

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $errorMsg = urlencode($e->getMessage());
    $redirectPage = $_POST['redirect'] ?? 'dashboard';
    header("Location: /?page={$redirectPage}&error={$errorMsg}");
    exit;
}

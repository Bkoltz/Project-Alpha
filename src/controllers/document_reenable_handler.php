<?php
// src/controllers/document_reenable_handler.php
// Updated: uses unified contracts table for all contract types
require_once __DIR__ . '/../config/db.php';

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
            $st = $pdo->prepare('SELECT * FROM quotes WHERE id=? FOR UPDATE');
            $st->execute([$id]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new Exception('Quote not found');
            
            if ($doc['status'] !== 'rejected') {
                throw new Exception('Only rejected quotes can be re-enabled');
            }
            
            $pdo->prepare("UPDATE quotes SET status='pending', document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$id]);
            
            $redirectPage = 'quote/quote-details';
            break;

        case 'contract':
            $st = $pdo->prepare('SELECT * FROM contracts WHERE id=? FOR UPDATE');
            $st->execute([$id]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new Exception('Contract not found');
            
            if (!in_array($doc['status'], ['cancelled', 'denied', 'void'])) {
                throw new Exception('Only cancelled/denied/void contracts can be re-enabled');
            }
            
            $pdo->prepare("UPDATE contracts SET status='pending', voided_at=NULL, document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$id]);
            
            $pdo->prepare("UPDATE invoices SET status='unpaid', document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE contract_id=? AND status='void'")
                ->execute([$id]);
            
            try {
                $pdo->prepare('UPDATE public_links SET revoked=0, redirect=NULL WHERE type="invoice" AND record_id IN (SELECT id FROM invoices WHERE contract_id=?) AND revoked=1')
                    ->execute([$id]);
            } catch (Throwable $_e) { /* ignore */ }
            
            $redirectPage = 'contract/contract-details';
            break;

        case 'invoice':
            $st = $pdo->prepare('SELECT * FROM invoices WHERE id=? FOR UPDATE');
            $st->execute([$id]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new Exception('Invoice not found');
            
            if ($doc['status'] !== 'void') {
                throw new Exception('Only void invoices can be re-enabled');
            }
            
            $pdo->prepare("UPDATE invoices SET status='unpaid', document_date=CURRENT_TIMESTAMP, document_date_updated_at=CURRENT_TIMESTAMP WHERE id=?")
                ->execute([$id]);
            
            try {
                $pdo->prepare('UPDATE public_links SET revoked=0, redirect=NULL WHERE type="invoice" AND record_id=? AND revoked=1')
                    ->execute([$id]);
            } catch (Throwable $_e) { /* ignore */ }
            
            $redirectPage = 'invoice/invoice-details';
            break;

        case 'long_term_contract':
            $st = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="long_term" FOR UPDATE');
            $st->execute([$id]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new Exception('Long-term contract not found');
            
            if ($doc['status'] !== 'cancelled') {
                throw new Exception('Only cancelled long-term contracts can be re-enabled');
            }
            
            $pdo->prepare("UPDATE contracts SET status='draft' WHERE id=?")
                ->execute([$id]);
            
            $redirectPage = 'contract/long-term-contract-details';
            break;

        case 'on_demand_contract':
            $st = $pdo->prepare('SELECT * FROM contracts WHERE id=? AND contract_type="on_demand" FOR UPDATE');
            $st->execute([$id]);
            $doc = $st->fetch(PDO::FETCH_ASSOC);
            if (!$doc) throw new Exception('On-demand contract not found');
            
            if ($doc['status'] !== 'cancelled') {
                throw new Exception('Only cancelled on-demand contracts can be re-enabled');
            }
            
            $pdo->prepare("UPDATE contracts SET status='draft' WHERE id=?")
                ->execute([$id]);
            
            $redirectPage = 'contract/on-demand-contract-details';
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

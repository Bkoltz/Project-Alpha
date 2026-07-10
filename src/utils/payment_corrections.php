<?php

declare(strict_types=1);

require_once __DIR__ . '/invoice_lifecycle.php';

/**
 * Move a real payment to the invoice it should have paid and optionally
 * reverse a duplicate manual entry on the target invoice.
 *
 * This is an accounting correction only. It never calls a payment processor,
 * creates a refund, or changes refunded_amount.
 *
 * @return array{
 *   correction_id:int,
 *   source_invoice_id:int,
 *   target_invoice_id:int,
 *   moved_payment_id:int,
 *   reversed_payment_id:int|null,
 *   source_voided:bool,
 *   source_status:string,
 *   target_status:string
 * }
 */
function payment_reallocate_to_invoice(
    PDO $pdo,
    int $paymentId,
    int $targetInvoiceId,
    ?int $replacementPaymentId,
    bool $voidSource,
    array $appConfig,
    string $reason,
    ?int $userId = null
): array {
    if ($paymentId <= 0 || $targetInvoiceId <= 0) {
        throw new RuntimeException('Select a payment and target invoice.');
    }

    $reason = trim(preg_replace('/\s+/', ' ', $reason) ?? '');
    if ($reason === '') {
        throw new RuntimeException('A correction reason is required.');
    }
    if (mb_strlen($reason) > 500) {
        throw new RuntimeException('The correction reason must be 500 characters or fewer.');
    }

    invoice_ensure_payments_schema($pdo);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $paymentStmt = $pdo->prepare('
            SELECT p.*, i.status AS source_invoice_status, i.collection_mode AS source_collection_mode
            FROM payments p
            JOIN invoices i ON i.id = p.invoice_id
            WHERE p.id = ?
            FOR UPDATE
        ');
        $paymentStmt->execute([$paymentId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$payment) {
            throw new RuntimeException('Payment not found or is not linked to an invoice.');
        }

        $sourceInvoiceId = (int)$payment['invoice_id'];
        if ($sourceInvoiceId === $targetInvoiceId) {
            throw new RuntimeException('The payment is already assigned to that invoice.');
        }
        if (strtolower((string)$payment['status']) !== 'succeeded') {
            throw new RuntimeException('Only a successful payment can be reallocated.');
        }
        if (!empty($payment['project_invoice_payment_id']) || (string)$payment['source_collection_mode'] !== 'direct') {
            throw new RuntimeException('Project invoice payments must be corrected from the project invoice workflow.');
        }
        if ((float)$payment['refunded_amount'] > 0.005 || (float)$payment['disputed_amount'] > 0.005) {
            throw new RuntimeException('A refunded or disputed payment cannot be reallocated.');
        }

        $financialEvent = $pdo->prepare('
            SELECT COUNT(*)
            FROM stripe_refunds
            WHERE payment_id = ? AND LOWER(status) NOT IN ("failed", "canceled", "cancelled")
        ');
        $financialEvent->execute([$paymentId]);
        if ((int)$financialEvent->fetchColumn() > 0) {
            throw new RuntimeException('This payment has a Stripe refund in progress or completed and cannot be reallocated.');
        }

        $targetStmt = $pdo->prepare('
            SELECT id, client_id, contract_id, organization_id, status, total, collection_mode
            FROM invoices
            WHERE id = ?
            FOR UPDATE
        ');
        $targetStmt->execute([$targetInvoiceId]);
        $target = $targetStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$target) {
            throw new RuntimeException('Target invoice not found.');
        }
        if (in_array(strtolower((string)$target['status']), ['draft', 'void', 'cancelled'], true)) {
            throw new RuntimeException('The target invoice must be finalized and active.');
        }
        if ((string)$target['collection_mode'] !== 'direct') {
            throw new RuntimeException('Select a directly collected invoice, not a project invoice item.');
        }
        if ((int)$target['client_id'] !== (int)$payment['client_id']) {
            throw new RuntimeException('Payments can only be moved between invoices for the same client.');
        }
        if ((int)($target['organization_id'] ?? 0) !== (int)($payment['organization_id'] ?? 0)) {
            throw new RuntimeException('Payments can only be moved within the same organization.');
        }

        $replacement = null;
        $replacementApplied = 0.0;
        if ($replacementPaymentId !== null && $replacementPaymentId > 0) {
            if ($replacementPaymentId === $paymentId) {
                throw new RuntimeException('The moved payment cannot also be the reversed payment.');
            }
            $replacementStmt = $pdo->prepare('SELECT * FROM payments WHERE id = ? FOR UPDATE');
            $replacementStmt->execute([$replacementPaymentId]);
            $replacement = $replacementStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            if (!$replacement || (int)($replacement['invoice_id'] ?? 0) !== $targetInvoiceId) {
                throw new RuntimeException('The duplicate manual payment must belong to the target invoice.');
            }
            if (strtolower((string)$replacement['status']) !== 'succeeded') {
                throw new RuntimeException('The duplicate manual payment is no longer active.');
            }
            $hasProcessorIdentity = strtolower((string)$replacement['payment_method']) === 'stripe'
                || trim((string)($replacement['stripe_payment_intent_id'] ?? '')) !== ''
                || trim((string)($replacement['stripe_session_id'] ?? '')) !== ''
                || trim((string)($replacement['processor_provider'] ?? '')) !== ''
                || trim((string)($replacement['processor_payment_id'] ?? '')) !== '';
            if ($hasProcessorIdentity || !empty($replacement['project_invoice_payment_id'])) {
                throw new RuntimeException('Only a manual cash, check, card, bank transfer, or other entry can be reversed here.');
            }
            if ((float)$replacement['refunded_amount'] > 0.005 || (float)$replacement['disputed_amount'] > 0.005) {
                throw new RuntimeException('A refunded or disputed manual entry cannot be reversed as a duplicate.');
            }
            $replacementApplied = max(
                0.0,
                (float)$replacement['amount']
                    - (float)$replacement['refunded_amount']
                    - (float)$replacement['disputed_amount']
            );
        }

        $targetPaid = invoice_effective_paid_total($pdo, $targetInvoiceId);
        $targetPaidAfterReversal = max(0.0, $targetPaid - $replacementApplied);
        $movedAmount = (float)$payment['amount'];
        $targetAvailable = max(0.0, (float)$target['total'] - $targetPaidAfterReversal);
        if ($movedAmount > $targetAvailable + 0.005) {
            throw new RuntimeException(
                'Moving this payment would overpay the target invoice. Select the duplicate manual entry to reverse, or choose another invoice.'
            );
        }

        if ($replacement) {
            $pdo->prepare('
                UPDATE payments
                SET status = "reversed", reversed_at = NOW(), reversed_by = ?, reversal_reason = ?
                WHERE id = ?
            ')->execute([$userId, $reason, $replacementPaymentId]);
        }

        $pdo->prepare('
            UPDATE payments
            SET invoice_id = ?, contract_id = ?, client_id = ?, organization_id = ?
            WHERE id = ?
        ')->execute([
            $targetInvoiceId,
            !empty($target['contract_id']) ? (int)$target['contract_id'] : null,
            (int)$target['client_id'],
            !empty($target['organization_id']) ? (int)$target['organization_id'] : null,
            $paymentId,
        ]);
        $pdo->prepare('UPDATE payment_receipts SET invoice_id = ? WHERE payment_id = ?')
            ->execute([$targetInvoiceId, $paymentId]);

        $sourceTotals = invoice_refresh_payment_totals($pdo, $sourceInvoiceId);
        $targetTotals = invoice_refresh_payment_totals($pdo, $targetInvoiceId);

        $sourceWasVoided = false;
        if ($voidSource) {
            invoice_void($pdo, $sourceInvoiceId, $appConfig, $reason, $userId);
            $sourceWasVoided = true;
            $sourceTotals['status'] = 'void';
        }

        $insert = $pdo->prepare('
            INSERT INTO payment_corrections
                (moved_payment_id, reversed_payment_id, source_invoice_id, target_invoice_id, corrected_by, source_voided, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ');
        $insert->execute([
            $paymentId,
            $replacement ? $replacementPaymentId : null,
            $sourceInvoiceId,
            $targetInvoiceId,
            $userId,
            $sourceWasVoided ? 1 : 0,
            $reason,
        ]);
        $correctionId = (int)$pdo->lastInsertId();

        if ($ownsTransaction) {
            $pdo->commit();
        }

        return [
            'correction_id' => $correctionId,
            'source_invoice_id' => $sourceInvoiceId,
            'target_invoice_id' => $targetInvoiceId,
            'moved_payment_id' => $paymentId,
            'reversed_payment_id' => $replacement ? $replacementPaymentId : null,
            'source_voided' => $sourceWasVoided,
            'source_status' => (string)$sourceTotals['status'],
            'target_status' => (string)$targetTotals['status'],
        ];
    } catch (Throwable $e) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

<?php

declare(strict_types=1);

require_once __DIR__ . '/invoice_lifecycle.php';
require_once __DIR__ . '/../services/StripeService.php';

function payment_is_processor_backed(array $payment): bool
{
    return strtolower((string)($payment['payment_method'] ?? '')) === 'stripe'
        || trim((string)($payment['processor_provider'] ?? '')) !== ''
        || trim((string)($payment['processor_payment_id'] ?? '')) !== ''
        || trim((string)($payment['stripe_payment_intent_id'] ?? '')) !== ''
        || trim((string)($payment['stripe_session_id'] ?? '')) !== '';
}

/**
 * Read the authoritative refunded total from Stripe before PA clears a legacy
 * local-only refund value. This function never creates a refund.
 */
function payment_verify_stripe_refunded_amount(array $payment, array $appConfig): float
{
    if (!payment_is_processor_backed($payment)) {
        throw new RuntimeException('Only a Stripe-backed payment can be verified with Stripe.');
    }

    $stripe = StripeService::fromAppConfig($appConfig);
    if (!$stripe) {
        throw new RuntimeException('Stripe must be configured before a local refund record can be cleared.');
    }

    $charge = null;
    $paymentIntentId = trim((string)($payment['stripe_payment_intent_id'] ?? ''));
    if ($paymentIntentId === '') {
        $processorId = trim((string)($payment['processor_payment_id'] ?? ''));
        if (str_starts_with($processorId, 'pi_')) {
            $paymentIntentId = $processorId;
        } elseif (str_starts_with($processorId, 'ch_')) {
            $charge = $stripe->getCharge($processorId);
        }
    }

    if ($paymentIntentId !== '') {
        $intent = $stripe->getPaymentIntentWithBalanceTransaction($paymentIntentId);
        if (is_array($intent['latest_charge'] ?? null)) {
            $charge = $intent['latest_charge'];
        } elseif (is_array($intent['charges']['data'][0] ?? null)) {
            $charge = $intent['charges']['data'][0];
        } elseif (is_string($intent['latest_charge'] ?? null) && $intent['latest_charge'] !== '') {
            $charge = $stripe->getCharge((string)$intent['latest_charge']);
        }
    }

    if (!is_array($charge) || !array_key_exists('amount_refunded', $charge)) {
        throw new RuntimeException('Stripe did not return an authoritative refund total for this payment. No correction was made.');
    }

    return round(max(0, (int)$charge['amount_refunded']) / 100, 2);
}

/**
 * Move a real payment to the invoice it should have paid, reverse selected
 * duplicate manual entries, and optionally void the accidental source invoice.
 *
 * No money moves in this workflow. A legacy local refund value may be cleared
 * only after Stripe has been queried and confirms that its refunded total is $0.
 *
 * @param int[] $replacementPaymentIds
 * @return array{
 *   correction_id:int,
 *   source_invoice_id:int,
 *   target_invoice_id:int,
 *   moved_payment_id:int,
 *   reversed_payment_id:int|null,
 *   reversed_payment_ids:int[],
 *   cleared_local_refund_amount:float,
 *   source_voided:bool,
 *   source_status:string,
 *   target_status:string
 * }
 */
function payment_reallocate_to_invoice(
    PDO $pdo,
    int $paymentId,
    int $targetInvoiceId,
    array $replacementPaymentIds,
    bool $clearLocalRefund,
    ?float $processorRefundVerifiedAmount,
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

    $replacementPaymentIds = array_values(array_unique(array_filter(
        array_map('intval', $replacementPaymentIds),
        static fn (int $id): bool => $id > 0
    )));
    sort($replacementPaymentIds, SORT_NUMERIC);

    invoice_ensure_payments_schema($pdo);
    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $paymentStmt = $pdo->prepare('
            SELECT p.*, i.status AS source_invoice_status, i.collection_mode AS source_collection_mode,
                   i.client_id AS source_invoice_client_id,
                   c.organization_id AS source_client_organization_id
            FROM payments p
            JOIN invoices i ON i.id = p.invoice_id
            JOIN clients c ON c.id = i.client_id
            WHERE p.id = ?
            FOR UPDATE
        ');
        $paymentStmt->execute([$paymentId]);
        $payment = $paymentStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$payment) {
            throw new RuntimeException('Payment not found or is not linked to an invoice.');
        }

        $sourceInvoiceId = (int)$payment['invoice_id'];
        $sourceClientId = (int)$payment['source_invoice_client_id'];
        $sourceOrganizationId = $payment['source_client_organization_id'] !== null
            ? (int)$payment['source_client_organization_id']
            : null;
        if ($sourceInvoiceId === $targetInvoiceId) {
            throw new RuntimeException('The payment is already assigned to that invoice.');
        }
        if (strtolower((string)$payment['status']) !== 'succeeded') {
            throw new RuntimeException('Only a successful payment can be reallocated.');
        }
        $sourceCollectionMode = trim((string)($payment['source_collection_mode'] ?? '')) ?: 'direct';
        if (!empty($payment['project_invoice_payment_id']) || $sourceCollectionMode !== 'direct') {
            throw new RuntimeException('Project invoice payments must be corrected from the project invoice workflow.');
        }
        if ((float)$payment['disputed_amount'] > 0.005) {
            throw new RuntimeException('A disputed payment cannot be reallocated.');
        }

        $financialEvent = $pdo->prepare('
            SELECT COUNT(*)
            FROM stripe_refunds
            WHERE payment_id = ? AND LOWER(status) NOT IN ("failed", "canceled", "cancelled")
        ');
        $financialEvent->execute([$paymentId]);
        $hasProcessorRefund = (int)$financialEvent->fetchColumn() > 0;

        $localRefund = max(0.0, (float)$payment['refunded_amount']);
        $clearedLocalRefund = 0.0;
        if ($localRefund > 0.005) {
            if (!$clearLocalRefund) {
                throw new RuntimeException(
                    'PA currently records a refund on this payment. If no money was refunded in Stripe, select the recovery checkbox so PA can verify Stripe and clear only the incorrect local value.'
                );
            }
            if (!payment_is_processor_backed($payment)) {
                throw new RuntimeException('A manual payment with a refund cannot be reallocated.');
            }
            if ($hasProcessorRefund) {
                throw new RuntimeException('A Stripe refund event exists for this payment, so its refund value cannot be cleared as a local correction.');
            }
            if ($processorRefundVerifiedAmount === null) {
                throw new RuntimeException('Stripe verification is required before clearing the local refund value.');
            }
            if ($processorRefundVerifiedAmount > 0.005) {
                throw new RuntimeException(
                    'Stripe reports that $' . number_format($processorRefundVerifiedAmount, 2)
                    . ' was actually refunded. This payment cannot be restored as an unrefunded payment.'
                );
            }
            $clearedLocalRefund = $localRefund;
        } elseif ($clearLocalRefund) {
            throw new RuntimeException('This payment does not have a local refund value to clear.');
        }

        $targetStmt = $pdo->prepare('
            SELECT i.id, i.client_id, i.contract_id, i.organization_id, i.status, i.total, i.collection_mode,
                   COALESCE(i.organization_id, c.organization_id) AS effective_organization_id,
                   c.organization_id AS target_client_organization_id
            FROM invoices i
            JOIN clients c ON c.id = i.client_id
            WHERE i.id = ?
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
        $targetCollectionMode = trim((string)($target['collection_mode'] ?? '')) ?: 'direct';
        if ($targetCollectionMode !== 'direct') {
            throw new RuntimeException('Select a directly collected invoice, not a project invoice item.');
        }
        if ((int)$target['client_id'] !== $sourceClientId) {
            throw new RuntimeException('Payments can only be moved between invoices for the same client.');
        }
        if (($target['target_client_organization_id'] !== null ? (int)$target['target_client_organization_id'] : null) !== $sourceOrganizationId) {
            throw new RuntimeException('Payments can only be moved within the same organization.');
        }

        $replacements = [];
        $targetReplacementApplied = 0.0;
        $replacementStmt = $pdo->prepare('
            SELECT p.*, i.client_id AS replacement_invoice_client_id,
                   c.organization_id AS replacement_client_organization_id
            FROM payments p
            JOIN invoices i ON i.id = p.invoice_id
            JOIN clients c ON c.id = i.client_id
            WHERE p.id = ?
            FOR UPDATE
        ');
        foreach ($replacementPaymentIds as $replacementPaymentId) {
            if ($replacementPaymentId === $paymentId) {
                throw new RuntimeException('The moved payment cannot also be a reversed payment.');
            }
            $replacementStmt->execute([$replacementPaymentId]);
            $replacement = $replacementStmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $replacementInvoiceId = (int)($replacement['invoice_id'] ?? 0);
            if (!$replacement || !in_array($replacementInvoiceId, [$sourceInvoiceId, $targetInvoiceId], true)) {
                throw new RuntimeException('Each duplicate manual payment must belong to the source or target invoice.');
            }
            $replacementOrganizationId = $replacement['replacement_client_organization_id'] !== null
                ? (int)$replacement['replacement_client_organization_id']
                : null;
            if ((int)$replacement['replacement_invoice_client_id'] !== $sourceClientId
                || $replacementOrganizationId !== $sourceOrganizationId) {
                throw new RuntimeException('Each duplicate manual payment must belong to the same client and organization.');
            }
            if (strtolower((string)$replacement['status']) !== 'succeeded') {
                throw new RuntimeException('A selected duplicate manual payment is no longer active.');
            }
            if (payment_is_processor_backed($replacement) || !empty($replacement['project_invoice_payment_id'])) {
                throw new RuntimeException('Only manual cash, check, card, bank transfer, or other entries can be reversed as duplicates.');
            }
            if ((float)$replacement['disputed_amount'] > 0.005) {
                throw new RuntimeException('A disputed manual entry cannot be reversed as a duplicate.');
            }

            // A mistaken manual refund may have already reduced this entry's
            // applied amount to zero. It can still be reversed for audit
            // cleanup, but only its current net application frees target room.
            $applied = max(
                0.0,
                (float)$replacement['amount']
                    - (float)$replacement['refunded_amount']
                    - (float)$replacement['disputed_amount']
            );
            if ($replacementInvoiceId === $targetInvoiceId) {
                $targetReplacementApplied += $applied;
            }
            $replacements[$replacementPaymentId] = $replacement;
        }

        $targetPaid = invoice_effective_paid_total($pdo, $targetInvoiceId);
        $targetPaidAfterReversal = max(0.0, $targetPaid - $targetReplacementApplied);
        $movedAmount = (float)$payment['amount'];
        $targetAvailable = max(0.0, (float)$target['total'] - $targetPaidAfterReversal);
        if ($movedAmount > $targetAvailable + 0.005) {
            throw new RuntimeException(
                'Moving this payment would overpay the target invoice. Select every duplicate manual entry on the target invoice, or choose another invoice.'
            );
        }

        foreach (array_keys($replacements) as $replacementPaymentId) {
            $pdo->prepare('
                UPDATE payments
                SET status = "reversed", reversed_at = NOW(), reversed_by = ?, reversal_reason = ?
                WHERE id = ?
            ')->execute([$userId, $reason, $replacementPaymentId]);
        }

        $pdo->prepare('
            UPDATE payments
            SET invoice_id = ?, contract_id = ?, client_id = ?, organization_id = ?, refunded_amount = ?
            WHERE id = ?
        ')->execute([
            $targetInvoiceId,
            !empty($target['contract_id']) ? (int)$target['contract_id'] : null,
            (int)$target['client_id'],
            $target['effective_organization_id'] !== null ? (int)$target['effective_organization_id'] : null,
            $clearedLocalRefund > 0.005 ? 0 : (float)$payment['refunded_amount'],
            $paymentId,
        ]);
        $pdo->prepare('UPDATE payment_receipts SET invoice_id = ? WHERE payment_id = ?')
            ->execute([$targetInvoiceId, $paymentId]);

        $sourceTotals = invoice_refresh_payment_totals($pdo, $sourceInvoiceId);
        $targetTotals = invoice_refresh_payment_totals($pdo, $targetInvoiceId);

        $sourceWasVoided = false;
        if ($voidSource) {
            if ((float)$sourceTotals['amount_paid'] > 0.005) {
                throw new RuntimeException(
                    'The source invoice still has an active payment. Select every duplicate manual entry on the source invoice before voiding it.'
                );
            }
            invoice_void($pdo, $sourceInvoiceId, $appConfig, $reason, $userId);
            $sourceWasVoided = true;
            $sourceTotals['status'] = 'void';
        }

        $reversedIds = array_map('intval', array_keys($replacements));
        $firstReversedId = $reversedIds[0] ?? null;
        $insert = $pdo->prepare('
            INSERT INTO payment_corrections
                (moved_payment_id, reversed_payment_id, reversed_payment_ids, source_invoice_id, target_invoice_id,
                 corrected_by, source_voided, cleared_local_refund_amount, processor_refund_verified_amount, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $insert->execute([
            $paymentId,
            $firstReversedId,
            $reversedIds ? json_encode($reversedIds) : null,
            $sourceInvoiceId,
            $targetInvoiceId,
            $userId,
            $sourceWasVoided ? 1 : 0,
            $clearedLocalRefund,
            $processorRefundVerifiedAmount,
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
            'reversed_payment_id' => $firstReversedId,
            'reversed_payment_ids' => $reversedIds,
            'cleared_local_refund_amount' => $clearedLocalRefund,
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

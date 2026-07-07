<?php
declare(strict_types=1);

require_once __DIR__ . '/payment_accounting.php';
require_once __DIR__ . '/StripeFeeCalculator.php';

function stripe_fee_policy_from_metadata(array $metadata, array $appConfig, float $surchargePaid = 0.0): string
{
    $raw = strtolower(trim((string)($metadata['pa_fee_policy'] ?? $metadata['surcharge_type'] ?? $appConfig['stripe_surcharge_type'] ?? '')));
    if ($raw === 'merchant') {
        return 'merchant_absorbs';
    }
    if ($raw === 'client') {
        return 'client_pays';
    }
    if (in_array($raw, ['merchant_absorbs', 'client_pays', 'split'], true)) {
        return $raw;
    }
    if ($surchargePaid <= 0.005) {
        return 'merchant_absorbs';
    }
    return 'unknown';
}

function stripe_processor_fields_from_normalized(array $tx, array $appConfig, float $appliedAmount, float $surchargePaid = 0.0): array
{
    $metadata = is_array($tx['metadata'] ?? null) ? $tx['metadata'] : [];
    $gross = round((float)($tx['gross_amount'] ?? 0), 2);
    if ($gross <= 0) {
        $gross = round(max(0.0, $appliedAmount + $surchargePaid), 2);
    }

    $feePolicy = stripe_fee_policy_from_metadata($metadata, $appConfig, $surchargePaid);
    $fee = array_key_exists('fee_amount', $tx) && $tx['fee_amount'] !== null ? round((float)$tx['fee_amount'], 2) : null;
    $net = array_key_exists('net_amount', $tx) && $tx['net_amount'] !== null ? round((float)$tx['net_amount'], 2) : null;
    if ($fee !== null && $net === null && $gross > 0) {
        $net = round(max(0.0, $gross - $fee), 2);
    }
    if ($net !== null && $fee === null && $gross > 0) {
        $fee = round(max(0.0, $gross - $net), 2);
    }
    $feeSource = ($fee !== null && $net !== null) ? 'actual' : 'unknown';

    if ($feeSource !== 'actual' && $gross > 0 && $feePolicy !== 'unknown') {
        $estimate = StripeFeeCalculator::calculateFee($gross, $appConfig);
        $fee = round((float)$estimate['fee_total'], 2);
        $net = round(max(0.0, $gross - $fee), 2);
        $feeSource = 'estimated';
    }

    return [
        'processor_provider' => 'stripe',
        'processor_payment_id' => trim((string)($tx['provider_payment_id'] ?? '')),
        'processor_gross_amount' => $gross > 0 ? $gross : null,
        'processor_fee_amount' => $fee,
        'processor_net_amount' => $net,
        'processor_fee_policy' => $feePolicy,
        'processor_fee_source' => $feeSource,
    ];
}

function stripe_update_payment_processor_fields(PDO $pdo, int $paymentId, array $tx, array $appConfig): array
{
    $payment = $pdo->prepare('SELECT amount,surcharge_paid FROM payments WHERE id=? LIMIT 1');
    $payment->execute([$paymentId]);
    $row = $payment->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row) {
        throw new RuntimeException('Payment not found.');
    }

    $fields = stripe_processor_fields_from_normalized(
        $tx,
        $appConfig,
        (float)($row['amount'] ?? 0),
        (float)($row['surcharge_paid'] ?? 0)
    );

    $pdo->prepare(
        'UPDATE payments
         SET processor_provider=?, processor_payment_id=?, processor_gross_amount=?, processor_fee_amount=?,
             processor_net_amount=?, processor_fee_policy=?, processor_fee_source=?
         WHERE id=?'
    )->execute([
        $fields['processor_provider'],
        $fields['processor_payment_id'] !== '' ? $fields['processor_payment_id'] : null,
        $fields['processor_gross_amount'],
        $fields['processor_fee_amount'],
        $fields['processor_net_amount'],
        $fields['processor_fee_policy'],
        $fields['processor_fee_source'],
        $paymentId,
    ]);

    return $fields;
}

function stripe_update_project_payment_processor_fields(PDO $pdo, int $projectPaymentId, array $tx, array $appConfig): array
{
    $payment = $pdo->prepare('SELECT amount FROM project_invoice_payments WHERE id=? LIMIT 1');
    $payment->execute([$projectPaymentId]);
    $row = $payment->fetch(PDO::FETCH_ASSOC) ?: [];
    if (!$row) {
        throw new RuntimeException('Project payment not found.');
    }

    $fields = stripe_processor_fields_from_normalized($tx, $appConfig, (float)($row['amount'] ?? 0), 0.0);
    $pdo->prepare(
        'UPDATE project_invoice_payments
         SET processor_provider=?, processor_payment_id=?, processor_gross_amount=?, processor_fee_amount=?,
             processor_net_amount=?, processor_fee_policy=?, processor_fee_source=?
         WHERE id=?'
    )->execute([
        $fields['processor_provider'],
        $fields['processor_payment_id'] !== '' ? $fields['processor_payment_id'] : null,
        $fields['processor_gross_amount'],
        $fields['processor_fee_amount'],
        $fields['processor_net_amount'],
        $fields['processor_fee_policy'],
        $fields['processor_fee_source'],
        $projectPaymentId,
    ]);

    stripe_allocate_project_processor_fields($pdo, $projectPaymentId, $fields);
    return $fields;
}

function stripe_allocate_project_processor_fields(PDO $pdo, int $projectPaymentId, array $fields): void
{
    if ($projectPaymentId <= 0) {
        return;
    }

    $children = $pdo->prepare('SELECT id,amount FROM payments WHERE project_invoice_payment_id=? ORDER BY id ASC');
    $children->execute([$projectPaymentId]);
    $rows = $children->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        return;
    }

    $totalApplied = 0.0;
    foreach ($rows as $row) {
        $totalApplied += max(0.0, (float)$row['amount']);
    }
    if ($totalApplied <= 0) {
        return;
    }

    $remainingGross = $fields['processor_gross_amount'];
    $remainingFee = $fields['processor_fee_amount'];
    $remainingNet = $fields['processor_net_amount'];
    $lastIndex = count($rows) - 1;

    foreach ($rows as $index => $row) {
        $ratio = max(0.0, (float)$row['amount']) / $totalApplied;
        $gross = $fields['processor_gross_amount'] !== null ? round((float)$fields['processor_gross_amount'] * $ratio, 2) : null;
        $fee = $fields['processor_fee_amount'] !== null ? round((float)$fields['processor_fee_amount'] * $ratio, 2) : null;
        $net = $fields['processor_net_amount'] !== null ? round((float)$fields['processor_net_amount'] * $ratio, 2) : null;
        if ($index === $lastIndex) {
            $gross = $remainingGross;
            $fee = $remainingFee;
            $net = $remainingNet;
        }
        if ($remainingGross !== null && $gross !== null) {
            $remainingGross = round((float)$remainingGross - $gross, 2);
        }
        if ($remainingFee !== null && $fee !== null) {
            $remainingFee = round((float)$remainingFee - $fee, 2);
        }
        if ($remainingNet !== null && $net !== null) {
            $remainingNet = round((float)$remainingNet - $net, 2);
        }

        $childProcessorId = ($fields['processor_payment_id'] ?? '') !== ''
            ? (string)$fields['processor_payment_id'] . ':allocation:' . (int)$row['id']
            : null;
        $pdo->prepare(
            'UPDATE payments
             SET processor_provider=?, processor_payment_id=?, processor_gross_amount=?, processor_fee_amount=?,
                 processor_net_amount=?, processor_fee_policy=?, processor_fee_source=?
             WHERE id=?'
        )->execute([
            $fields['processor_provider'] ?? 'stripe',
            $childProcessorId,
            $gross,
            $fee,
            $net,
            $fields['processor_fee_policy'] ?? 'unknown',
            $fields['processor_fee_source'] ?? 'unknown',
            (int)$row['id'],
        ]);
    }
}

function stripe_backfill_net_income(PDO $pdo, StripeService $stripe, array $appConfig, int $limit = 50): array
{
    $limit = max(1, min(500, $limit));
    $result = ['updated' => 0, 'estimated' => 0, 'unknown' => 0, 'skipped' => 0, 'failed' => 0];

    $payments = $pdo->query(
        'SELECT id,stripe_payment_intent_id
         FROM payments
         WHERE stripe_payment_intent_id IS NOT NULL
           AND stripe_payment_intent_id <> ""
           AND (processor_net_amount IS NULL OR processor_fee_source <> "actual")
         ORDER BY payment_date DESC, id DESC
         LIMIT ' . $limit
    )->fetchAll(PDO::FETCH_ASSOC);

    foreach ($payments as $payment) {
        try {
            $pi = $stripe->getPaymentIntentWithBalanceTransaction((string)$payment['stripe_payment_intent_id']);
            $fields = stripe_update_payment_processor_fields($pdo, (int)$payment['id'], $stripe->normalizePaymentIntentForImport($pi), $appConfig);
            stripe_backfill_count_result($result, $fields);
        } catch (Throwable $e) {
            $result['failed']++;
            @error_log('[stripe_backfill_net_income] Payment ' . (int)$payment['id'] . ' failed: ' . $e->getMessage());
        }
    }

    $remaining = $limit - count($payments);
    if ($remaining > 0) {
        $projectPayments = $pdo->query(
            'SELECT id,stripe_payment_intent_id
             FROM project_invoice_payments
             WHERE stripe_payment_intent_id IS NOT NULL
               AND stripe_payment_intent_id <> ""
               AND (processor_net_amount IS NULL OR processor_fee_source <> "actual")
             ORDER BY payment_date DESC, id DESC
             LIMIT ' . $remaining
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($projectPayments as $payment) {
            try {
                $pi = $stripe->getPaymentIntentWithBalanceTransaction((string)$payment['stripe_payment_intent_id']);
                $fields = stripe_update_project_payment_processor_fields($pdo, (int)$payment['id'], $stripe->normalizePaymentIntentForImport($pi), $appConfig);
                stripe_backfill_count_result($result, $fields);
            } catch (Throwable $e) {
                $result['failed']++;
                @error_log('[stripe_backfill_net_income] Project payment ' . (int)$payment['id'] . ' failed: ' . $e->getMessage());
            }
        }
    }

    return $result;
}

function stripe_backfill_count_result(array &$result, array $fields): void
{
    $source = (string)($fields['processor_fee_source'] ?? 'unknown');
    if ($source === 'actual') {
        $result['updated']++;
    } elseif ($source === 'estimated') {
        $result['estimated']++;
    } else {
        $result['unknown']++;
    }
}

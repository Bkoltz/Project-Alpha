<?php
declare(strict_types=1);

function payment_accounting_surcharge_expr(string $alias = 'p'): string
{
    $a = payment_accounting_alias($alias);
    return "GREATEST(COALESCE({$a}.surcharge_paid,0) - CASE WHEN COALESCE({$a}.surcharge_refunded,0)=1 THEN COALESCE({$a}.surcharge_refund_amount,0) ELSE 0 END, 0)";
}

function payment_accounting_invoice_applied_expr(string $alias = 'p'): string
{
    $a = payment_accounting_alias($alias);
    return "GREATEST(COALESCE({$a}.amount,0)-COALESCE({$a}.refunded_amount,0)-COALESCE({$a}.disputed_amount,0),0)";
}

function payment_accounting_net_income_expr(string $alias = 'p'): string
{
    $a = payment_accounting_alias($alias);
    $surcharge = payment_accounting_surcharge_expr($a);
    $grossFallback = "GREATEST(COALESCE({$a}.amount,0)+{$surcharge},0)";

    return "GREATEST((CASE
        WHEN {$a}.processor_net_amount IS NOT NULL THEN {$a}.processor_net_amount
        WHEN {$a}.processor_gross_amount IS NOT NULL AND {$a}.processor_fee_amount IS NOT NULL THEN GREATEST({$a}.processor_gross_amount-{$a}.processor_fee_amount,0)
        WHEN {$a}.processor_fee_amount IS NOT NULL THEN GREATEST({$grossFallback}-{$a}.processor_fee_amount,0)
        ELSE {$grossFallback}
    END)-COALESCE({$a}.refunded_amount,0)-COALESCE({$a}.disputed_amount,0),0)";
}

function payment_accounting_net_income(array $payment): float
{
    $amount = (float)($payment['amount'] ?? 0);
    $surcharge = max(0.0, (float)($payment['surcharge_paid'] ?? 0));
    if (!empty($payment['surcharge_refunded'])) {
        $surcharge = max(0.0, $surcharge - (float)($payment['surcharge_refund_amount'] ?? 0));
    }

    $base = $amount + $surcharge;
    if (array_key_exists('processor_net_amount', $payment) && $payment['processor_net_amount'] !== null && $payment['processor_net_amount'] !== '') {
        $base = (float)$payment['processor_net_amount'];
    } elseif (($payment['processor_gross_amount'] ?? null) !== null && ($payment['processor_fee_amount'] ?? null) !== null) {
        $base = max(0.0, (float)$payment['processor_gross_amount'] - (float)$payment['processor_fee_amount']);
    } elseif (($payment['processor_fee_amount'] ?? null) !== null) {
        $base = max(0.0, $base - (float)$payment['processor_fee_amount']);
    }

    return round(max(0.0, $base - (float)($payment['refunded_amount'] ?? 0) - (float)($payment['disputed_amount'] ?? 0)), 2);
}

function payment_accounting_alias(string $alias): string
{
    $alias = trim($alias);
    return preg_match('/^[A-Za-z0-9_]+$/', $alias) ? $alias : 'p';
}

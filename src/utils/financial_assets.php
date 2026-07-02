<?php
declare(strict_types=1);

function financial_asset_money(float $amount): string
{
    return '$' . number_format($amount, 2);
}

function financial_asset_date(?string $date): string
{
    if (!$date) {
        return '-';
    }
    $timestamp = strtotime($date);
    return $timestamp ? date('M j, Y', $timestamp) : '-';
}

function financial_asset_status_label(?string $status): string
{
    $status = trim((string)$status);
    if ($status === '') {
        return 'Active';
    }
    return ucwords(str_replace('_', ' ', $status));
}

function financial_asset_status_class(?string $status): string
{
    $status = strtolower((string)$status);
    if (in_array($status, ['active'], true)) {
        return 'active';
    }
    if (in_array($status, ['maintenance', 'planned'], true)) {
        return 'pending';
    }
    if (in_array($status, ['retired', 'sold', 'disposed'], true)) {
        return 'inactive';
    }
    if ($status === 'lost') {
        return 'void';
    }
    return preg_replace('/[^a-z0-9_-]/', '', $status) ?: 'active';
}

function financial_asset_depreciation(array $asset, ?DateTimeImmutable $asOf = null): array
{
    $asOf = $asOf ?: new DateTimeImmutable('today');
    $cost = max(0.0, (float)($asset['purchase_cost'] ?? 0));
    $salvage = max(0.0, (float)($asset['salvage_value'] ?? 0));
    $depreciable = max(0.0, $cost - $salvage);
    $lifeMonths = max(0, (int)($asset['useful_life_months'] ?? 0));
    $method = (string)($asset['depreciation_method'] ?? 'straight_line');
    $monthly = 0.0;
    $elapsedMonths = 0;
    $accumulated = 0.0;

    if ($method === 'straight_line' && $depreciable > 0 && $lifeMonths > 0) {
        $monthly = $depreciable / $lifeMonths;
        $startRaw = $asset['depreciation_start_date'] ?? $asset['purchase_date'] ?? null;
        if ($startRaw) {
            try {
                $start = new DateTimeImmutable((string)$startRaw);
                if ($start <= $asOf) {
                    $elapsedMonths = (((int)$asOf->format('Y') - (int)$start->format('Y')) * 12)
                        + ((int)$asOf->format('n') - (int)$start->format('n')) + 1;
                    $elapsedMonths = min(max(0, $elapsedMonths), $lifeMonths);
                    $accumulated = min($depreciable, $monthly * $elapsedMonths);
                }
            } catch (Throwable $ignored) {
                $elapsedMonths = 0;
            }
        }
    }

    $bookValue = max($salvage, $cost - $accumulated);
    if ($method === 'none') {
        $bookValue = $cost;
    }

    return [
        'monthly' => round($monthly, 2),
        'elapsed_months' => $elapsedMonths,
        'accumulated' => round($accumulated, 2),
        'book_value' => round($bookValue, 2),
        'depreciable_basis' => round($depreciable, 2),
    ];
}

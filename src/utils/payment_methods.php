<?php

declare(strict_types=1);

function pa_payment_method_key(string $method): string
{
    $key = strtolower(trim($method));
    $key = str_replace(['-', ' '], '_', $key);
    $key = preg_replace('/_+/', '_', $key) ?? $key;
    return trim($key, '_');
}

function pa_payment_method_label(string $method): string
{
    $key = pa_payment_method_key($method);
    return match ($key) {
        'stripe' => 'Credit Card',
        'card', 'credit_card' => 'Credit Card',
        'bank', 'bank_transfer', 'ach' => 'Bank Transfer',
        'cash' => 'Cash',
        'check', 'cheque' => 'Check',
        default => ucwords(str_replace('_', ' ', $key !== '' ? $key : trim($method))),
    };
}

/**
 * @return list<array{key:string,label:string,raw:string}>
 */
function pa_payment_methods_from_config(array $appConfig): array
{
    $rawMethods = $appConfig['payment_methods'] ?? [];
    if (is_string($rawMethods)) {
        $decoded = json_decode($rawMethods, true);
        $rawMethods = is_array($decoded) ? $decoded : preg_split('/\r?\n/', $rawMethods);
    }
    if (!is_array($rawMethods)) {
        $rawMethods = [];
    }

    $methods = [];
    $hasStripe = false;
    foreach ($rawMethods as $method) {
        if (is_array($method)) {
            $method = (string)($method['name'] ?? $method['label'] ?? '');
        } else {
            $method = (string)$method;
        }
        $method = trim($method);
        if ($method === '') {
            continue;
        }
        $key = pa_payment_method_key($method);
        if ($key === 'stripe') {
            $hasStripe = true;
        }
        $methods[] = ['key' => $key, 'label' => pa_payment_method_label($method), 'raw' => $method];
    }

    $seen = [];
    $normalized = [];
    foreach ($methods as $method) {
        $key = $method['key'];
        if ($hasStripe && in_array($key, ['card', 'credit_card'], true)) {
            continue;
        }
        $dedupeKey = $key === 'credit_card' ? 'card' : $key;
        if (isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;
        $normalized[] = $method;
    }

    return $normalized;
}

/**
 * @param list<string> $methods
 * @return list<string>
 */
function pa_normalized_payment_method_values(array $methods): array
{
    $hasStripe = false;
    foreach ($methods as $method) {
        if (pa_payment_method_key((string)$method) === 'stripe') {
            $hasStripe = true;
            break;
        }
    }

    $seen = [];
    $normalized = [];
    foreach ($methods as $method) {
        $method = trim((string)$method);
        if ($method === '') {
            continue;
        }
        $key = pa_payment_method_key($method);
        if ($hasStripe && in_array($key, ['card', 'credit_card'], true)) {
            continue;
        }
        $dedupeKey = $key === 'credit_card' ? 'card' : $key;
        if (isset($seen[$dedupeKey])) {
            continue;
        }
        $seen[$dedupeKey] = true;
        $normalized[] = $method;
    }

    return $normalized;
}

function pa_payment_methods_has(array $appConfig, string $key): bool
{
    $key = pa_payment_method_key($key);
    foreach (pa_payment_methods_from_config($appConfig) as $method) {
        if ($method['key'] === $key) {
            return true;
        }
    }
    return false;
}

<?php
declare(strict_types=1);

function pa_contract_billing_start_mode(array $contract): string
{
    $mode = (string)($contract['billing_start_mode'] ?? 'on_upload');
    return in_array($mode, ['on_upload', 'manual'], true) ? $mode : 'on_upload';
}

function pa_long_term_starts_billing_on_upload(array $contract): bool
{
    return (string)($contract['contract_type'] ?? '') === 'long_term'
        && pa_contract_billing_start_mode($contract) === 'on_upload';
}

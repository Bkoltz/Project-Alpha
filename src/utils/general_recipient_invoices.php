<?php

declare(strict_types=1);

const PA_GENERAL_RECIPIENT_MODE = 'general';

function pa_invoice_recipient_presentation_mode(array $invoice): string
{
    return strtolower(trim((string)($invoice['recipient_presentation_mode'] ?? 'named'))) === PA_GENERAL_RECIPIENT_MODE
        ? PA_GENERAL_RECIPIENT_MODE
        : 'named';
}

function pa_invoice_is_general_recipient(array $invoice): bool
{
    return pa_invoice_recipient_presentation_mode($invoice) === PA_GENERAL_RECIPIENT_MODE;
}

/** General-recipient invoices are deliberate one-off, direct invoices only. */
function pa_general_recipient_invoice_is_eligible(array $invoice): bool
{
    return pa_invoice_is_general_recipient($invoice)
        && strtolower(trim((string)($invoice['invoice_type'] ?? 'regular'))) === 'regular'
        && strtolower(trim((string)($invoice['collection_mode'] ?? 'direct'))) === 'direct'
        && empty($invoice['contract_id'])
        && empty($invoice['project_id'])
        && empty($invoice['job_id'])
        && empty($invoice['service_location_id']);
}

function pa_general_recipient_public_receipt_window_open(array $invoice, ?int $now = null): bool
{
    if (!pa_invoice_is_general_recipient($invoice) || strtolower((string)($invoice['status'] ?? '')) !== 'paid') {
        return false;
    }
    $paidAt = trim((string)($invoice['paid_at'] ?? ''));
    $timestamp = $paidAt !== '' ? strtotime($paidAt) : false;
    if ($timestamp === false) {
        return false;
    }
    return $timestamp + (7 * 86400) > ($now ?? time());
}

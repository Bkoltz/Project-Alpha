<?php
declare(strict_types=1);

function pa_invoice_prefix(?string $invoiceType): string
{
    return match (strtolower(trim((string)$invoiceType))) {
        'long_term' => 'LTI',
        'on_demand' => 'ODI',
        default => 'I',
    };
}

function pa_invoice_label(mixed $docNumber, ?string $invoiceType = 'regular', mixed $fallback = null): string
{
    $number = $docNumber;
    if ($number === null || $number === '') {
        $number = $fallback;
    }
    if ($number === null || $number === '') {
        $number = '?';
    }

    return pa_invoice_prefix($invoiceType) . '-' . (string)$number;
}

function pa_invoice_label_from_row(array $invoice): string
{
    return pa_invoice_label(
        $invoice['doc_number'] ?? null,
        isset($invoice['invoice_type']) ? (string)$invoice['invoice_type'] : 'regular',
        $invoice['invoice_id'] ?? ($invoice['id'] ?? null)
    );
}

function pa_next_invoice_doc_number(PDO $pdo, string $invoiceType = 'regular'): int
{
    if ($invoiceType === 'regular') {
        $stmt = $pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices WHERE invoice_type = "regular" OR invoice_type IS NULL');
        return ((int)$stmt->fetchColumn()) + 1;
    }

    $stmt = $pdo->prepare('SELECT COALESCE(MAX(doc_number),0) FROM invoices WHERE invoice_type = ?');
    $stmt->execute([$invoiceType]);
    return ((int)$stmt->fetchColumn()) + 1;
}

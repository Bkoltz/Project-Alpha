<?php
declare(strict_types=1);

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

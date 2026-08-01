<?php

/**
 * Canonical invoice due-date helpers. A term-derived due date follows the
 * invoice document date; a manual due date never moves implicitly.
 */
function invoice_due_date_from_terms(string $documentDate, int $termDays): string
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($documentDate, 0, 10));
    if (!$date || $date->format('Y-m-d') !== substr($documentDate, 0, 10)) {
        throw new InvalidArgumentException('Invalid invoice document date.');
    }
    return $date->modify('+' . max(0, $termDays) . ' days')->format('Y-m-d');
}

function invoice_payment_terms_text(array $invoice, array $appConfig): string
{
    $days = $invoice['payment_terms_days'] ?? null;
    $source = (string)($invoice['due_date_source'] ?? 'manual');
    $dueDate = trim((string)($invoice['due_date'] ?? ''));

    if ($source === 'terms' && $days !== null && $days !== '') {
        $label = ((int)$days === 0) ? 'Due on receipt' : 'Net ' . (int)$days;
        if ($dueDate !== '') {
            $label .= ' (due ' . date('F j, Y', strtotime($dueDate)) . ')';
        }
        return $label;
    }

    if ($dueDate !== '') {
        return 'Due ' . date('F j, Y', strtotime($dueDate));
    }
    return trim((string)($appConfig['terms'] ?? ''));
}

/**
 * Update the document date and only recompute the due date when provenance
 * proves that it was derived from payment terms.
 *
 * @return array{document_date:string,due_date:?string,due_date_source:string,payment_terms_days:?int}
 */
function invoice_update_document_date(PDO $pdo, int $invoiceId, string $newDate): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr(trim($newDate), 0, 10));
    if (!$date || $date->format('Y-m-d') !== substr(trim($newDate), 0, 10)) {
        throw new InvalidArgumentException('Invalid document date.');
    }
    $normalized = $date->format('Y-m-d');

    $ownsTransaction = !$pdo->inTransaction();
    if ($ownsTransaction) {
        $pdo->beginTransaction();
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT due_date,due_date_source,payment_terms_days FROM invoices WHERE id=? FOR UPDATE'
        );
        $stmt->execute([$invoiceId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$invoice) {
            throw new RuntimeException('Invoice not found.');
        }

        $source = (string)($invoice['due_date_source'] ?? 'manual');
        $termDays = $invoice['payment_terms_days'] !== null
            ? max(0, (int)$invoice['payment_terms_days'])
            : null;
        $dueDate = $invoice['due_date'] ?: null;
        if ($source === 'terms' && $termDays !== null) {
            $dueDate = invoice_due_date_from_terms($normalized, $termDays);
        }

        $pdo->prepare(
            'UPDATE invoices
             SET document_date=?, document_date_updated_at=NOW(), due_date=?
             WHERE id=?'
        )->execute([$normalized . ' 00:00:00', $dueDate, $invoiceId]);

        if ($ownsTransaction) {
            $pdo->commit();
        }
        return [
            'document_date' => $normalized,
            'due_date' => $dueDate,
            'due_date_source' => $source,
            'payment_terms_days' => $termDays,
        ];
    } catch (Throwable $error) {
        if ($ownsTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
}

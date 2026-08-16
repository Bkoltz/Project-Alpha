<?php

declare(strict_types=1);

/** Allocate under the caller's transaction; all quote creation paths use this row lock. */
function pa_next_quote_doc_number(PDO $pdo, string $quoteType = 'regular'): int
{
    if (!in_array($quoteType, ['regular','long_term','on_demand'], true)) $quoteType = 'regular';
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    $row = $pdo->prepare('SELECT next_number FROM document_number_sequences WHERE document_type=\'quote\' AND document_subtype=?' . $suffix);
    $row->execute([$quoteType]);
    $next = $row->fetchColumn();
    $max = $pdo->prepare('SELECT COALESCE(MAX(doc_number),0)+1 FROM quotes WHERE quote_type=?');
    $max->execute([$quoteType]);
    $number = max((int)($next ?: 1), (int)$max->fetchColumn());
    if ($next === false) {
        $pdo->prepare('INSERT INTO document_number_sequences (document_type,document_subtype,next_number) VALUES (\'quote\',?,?)')->execute([$quoteType,$number+1]);
    } else {
        $pdo->prepare('UPDATE document_number_sequences SET next_number=? WHERE document_type=\'quote\' AND document_subtype=?')->execute([$number+1,$quoteType]);
    }
    return $number;
}

<?php
// src/utils/contract_signatures.php

function pa_contract_signature_type(string $title, int $index): string
{
    $lower = strtolower($title);
    if (str_contains($lower, 'admin')) {
        return 'admin';
    }
    if (str_contains($lower, 'witness')) {
        return 'witness';
    }
    return $index === 0 ? 'client' : 'client';
}

function pa_save_contract_signatures(PDO $pdo, int $contractId, array $titles, array $orders = [], array $required = []): void
{
    $stmt = $pdo->prepare('
        INSERT INTO contract_signatures (contract_id, signatory_type, signer_title, display_order, is_required)
        VALUES (?, ?, ?, ?, ?)
    ');

    $sequence = 0;
    foreach ($titles as $idx => $rawTitle) {
        $title = trim((string)$rawTitle);
        if ($title === '') {
            continue;
        }

        $sequence++;
        $order = isset($orders[$idx]) && is_numeric($orders[$idx]) ? (int)$orders[$idx] : $sequence;
        $requiredValue = 1;
        if (array_key_exists($idx, $required)) {
            $requiredValue = !empty($required[$idx]) ? 1 : 0;
        }

        $stmt->execute([
            $contractId,
            pa_contract_signature_type($title, $sequence - 1),
            $title,
            max(1, $order),
            $requiredValue,
        ]);
    }
}

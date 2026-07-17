<?php

declare(strict_types=1);

/**
 * Resolve a posted catalog reference against the authoritative library and
 * capture the internal fulfillment/compensation source without exposing it on
 * client documents. A mismatched name is treated as a manually edited line.
 */
function catalog_document_snapshot(PDO $pdo, int $itemLibraryId, array $line): array
{
    if ($itemLibraryId <= 0) {
        return ['item_library_id' => null, 'catalog_snapshot' => null];
    }

    $stmt = $pdo->prepare(
        'SELECT id,item_name,description,unit_price,entry_type,billing_unit,tax_behavior,is_active,updated_at
         FROM item_library WHERE id=? LIMIT 1'
    );
    $stmt->execute([$itemLibraryId]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$item) {
        return ['item_library_id' => null, 'catalog_snapshot' => null];
    }

    $postedName = trim((string)($line['item'] ?? $line['i'] ?? ''));
    if ($postedName === '' || $postedName !== trim((string)$item['item_name'])) {
        return ['item_library_id' => null, 'catalog_snapshot' => null];
    }

    $componentsStmt = $pdo->prepare(
        'SELECT c.id,c.work_type_id,c.name,c.description,c.quantity_behavior,c.fixed_quantity,
                c.expected_duration_minutes,c.assignment_required,c.compensation_method,
                c.compensation_amount,c.included_minutes,c.overage_rate,c.percentage,
                c.percentage_basis,c.eligibility_trigger,c.currency,c.display_order,c.updated_at,
                wt.code work_type_code,wt.name work_type_name
         FROM catalog_work_components c JOIN work_types wt ON wt.id=c.work_type_id
         WHERE c.item_library_id=? AND c.is_active=1 ORDER BY c.display_order,c.id'
    );
    $componentsStmt->execute([$itemLibraryId]);
    $components = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);

    $bundleItems = [];
    if ((string)$item['entry_type'] === 'bundle') {
        $bundleStmt = $pdo->prepare(
            'SELECT b.child_item_library_id item_library_id,b.quantity,b.display_order,
                    i.item_name,i.entry_type
             FROM catalog_bundle_items b JOIN item_library i ON i.id=b.child_item_library_id
             WHERE b.bundle_item_library_id=? ORDER BY b.display_order,b.child_item_library_id'
        );
        $bundleStmt->execute([$itemLibraryId]);
        foreach ($bundleStmt->fetchAll(PDO::FETCH_ASSOC) as $bundleItem) {
            $componentsStmt->execute([(int)$bundleItem['item_library_id']]);
            $childComponents = $componentsStmt->fetchAll(PDO::FETCH_ASSOC);
            $bundleItem['item_library_id'] = (int)$bundleItem['item_library_id'];
            $bundleItem['quantity'] = (string)$bundleItem['quantity'];
            $bundleItem['display_order'] = (int)$bundleItem['display_order'];
            $bundleItem['work_components'] = array_map(static function (array $component): array {
                foreach (['id','work_type_id','expected_duration_minutes','assignment_required','included_minutes','display_order'] as $key) {
                    if ($component[$key] !== null) $component[$key] = (int)$component[$key];
                }
                return $component;
            }, $childComponents);
            $bundleItems[] = $bundleItem;
        }
    }

    $snapshot = [
        'version' => 1,
        'captured_at' => gmdate('c'),
        'catalog' => [
            'id' => (int)$item['id'],
            'name' => (string)$item['item_name'],
            'description' => $item['description'],
            'unit_price' => (string)$item['unit_price'],
            'entry_type' => (string)$item['entry_type'],
            'billing_unit' => (string)$item['billing_unit'],
            'tax_behavior' => (string)$item['tax_behavior'],
            'updated_at' => (string)$item['updated_at'],
        ],
        'document_line' => [
            'name' => $postedName,
            'description' => (string)($line['description'] ?? $line['d'] ?? ''),
            'quantity' => (string)($line['quantity'] ?? $line['q'] ?? '0'),
            'unit_price' => (string)($line['unit_price'] ?? $line['p'] ?? '0'),
            'line_total' => (string)($line['line_total'] ?? $line['t'] ?? '0'),
            'billing_unit' => (string)($line['billing_unit'] ?? $line['u'] ?? 'each'),
        ],
        'work_components' => array_map(static function (array $component): array {
            foreach (['id','work_type_id','expected_duration_minutes','assignment_required','included_minutes','display_order'] as $key) {
                if ($component[$key] !== null) {
                    $component[$key] = (int)$component[$key];
                }
            }
            return $component;
        }, $components),
        'bundle_items' => $bundleItems,
    ];

    return [
        'item_library_id' => (int)$item['id'],
        'catalog_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
    ];
}

function catalog_document_unit(string $unit, string $fallback = 'each'): string
{
    return in_array($unit, ['each','hour','day','mile','project'], true) ? $unit : $fallback;
}

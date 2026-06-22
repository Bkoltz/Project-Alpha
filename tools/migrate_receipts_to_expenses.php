<?php
// tools/migrate_receipts_to_expenses.php
// One-time migration: create expense records for all existing receipts
// Run: docker compose exec -T web php /var/www/tools/migrate_receipts_to_expenses.php

require_once __DIR__ . '/../src/config/db.php';
require_once __DIR__ . '/../src/config/app.php';
require_once __DIR__ . '/../src/utils/audit.php';

$orgId = 1;
$migrated = 0;
$skipped = 0;
$errors = 0;

echo "Starting receipt-to-expense migration...\n";

// Fetch all receipts that don't already have an expense record
$stmt = $pdo->prepare('
    SELECT r.*, v.name as vendor_name
    FROM receipts r
    LEFT JOIN vendors v ON v.id = r.store_id
    LEFT JOIN expenses e ON e.receipt_id = r.id
    WHERE r.organization_id = ? AND e.id IS NULL
    ORDER BY r.receipt_date ASC
');
$stmt->execute([$orgId]);
$receipts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($receipts) . " receipts to migrate.\n";

foreach ($receipts as $r) {
    try {
        $stmt = $pdo->prepare('
            INSERT INTO expenses (organization_id, vendor_id, receipt_id, amount, total_amount, expense_date, description, created_by, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, "confirmed")
        ');
        $totalAmount = (float)$r['amount'];
        $stmt->execute([
            $orgId,
            $r['store_id'] ?: null,
            $r['id'],
            (float)$r['amount'],
            $totalAmount,
            $r['receipt_date'],
            $r['description'] ?? $r['title'] ?? 'Receipt ' . $r['id'],
            $r['uploaded_by'] ?? null,
        ]);
        $migrated++;
    } catch (Throwable $e) {
        $errors++;
        echo "  ERROR: Receipt {$r['id']}: " . $e->getMessage() . "\n";
    }
}

echo "\nMigration complete.\n";
echo "  Migrated: $migrated\n";
echo "  Skipped: $skipped\n";
echo "  Errors: $errors\n";

if ($migrated > 0) {
    audit_log($pdo, 'expense.migration', 'expense', null, ['migrated' => $migrated, 'errors' => $errors]);
}

echo "Done.\n";
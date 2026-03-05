<?php
/**
 * Migration: Add amount_paid column to invoices table
 * 
 * Run this from inside the Docker container:
 *   docker exec -it <container_name> php /var/www/src/migrations/add_amount_paid.php
 * 
 * Or from the host with Docker Compose:
 *   docker compose exec app php /var/www/src/migrations/add_amount_paid.php
 */
require_once __DIR__ . '/../config/db.php';

try {
    // Check if column exists
    $cols = $pdo->query('DESCRIBE invoices')->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('amount_paid', $cols)) {
        $pdo->exec('ALTER TABLE invoices ADD COLUMN amount_paid DECIMAL(12,2) DEFAULT 0');
        echo "Column 'amount_paid' added successfully.\n";
        
        // Update existing invoices with their paid amounts from payments table
        $pdo->exec('
            UPDATE invoices i
            SET amount_paid = COALESCE((
                SELECT SUM(amount) FROM payments p 
                WHERE p.invoice_id = i.id AND p.status = "succeeded"
            ), 0)
        ');
        echo "Existing invoice amounts updated.\n";
    } else {
        echo "Column 'amount_paid' already exists.\n";
    }
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

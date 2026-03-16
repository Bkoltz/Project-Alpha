<?php
/**
 * Migration: Add activity_log table for tracking public link events
 * 
 * Run in Docker:
 *   docker compose exec app php /var/www/src/migrations/add_activity_log.php
 */
require_once __DIR__ . '/../config/db.php';

try {
    $pdo->exec('
        CREATE TABLE IF NOT EXISTS activity_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(50) NOT NULL,
            document_type VARCHAR(20) NULL,
            document_id INT NULL,
            client_id INT NULL,
            description TEXT NOT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            metadata JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_activity_type (event_type),
            INDEX idx_activity_doc (document_type, document_id),
            INDEX idx_activity_client (client_id),
            INDEX idx_activity_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ');
    echo "Table 'activity_log' created successfully.\n";
    
} catch (Throwable $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}

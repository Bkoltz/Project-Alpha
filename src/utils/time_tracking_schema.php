<?php

function pa_time_tracking_table_has_column(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?
             LIMIT 1'
        );
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function pa_time_tracking_ensure_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS time_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            organization_id INT NULL,
            user_id INT NULL,
            team_member_id BIGINT UNSIGNED NULL,
            client_id INT NULL,
            project_id INT NULL,
            project_code VARCHAR(64) NULL,
            contract_id INT NULL,
            invoice_id INT NULL,
            service_item_id INT NULL,
            description TEXT NULL,
            started_at DATETIME NULL,
            ended_at DATETIME NULL,
            hours DECIMAL(10,2) NOT NULL DEFAULT 0,
            billable TINYINT(1) DEFAULT 1,
            billed TINYINT(1) DEFAULT 0,
            rate DECIMAL(10,2) DEFAULT 0,
            invoice_item_id INT DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_time_entries_user (user_id),
            INDEX idx_time_entries_client (client_id),
            INDEX idx_time_entries_project (project_id),
            INDEX idx_time_entries_project_code (project_code),
            INDEX idx_time_entries_contract (contract_id),
            INDEX idx_time_entries_invoice (invoice_id),
            INDEX idx_time_entries_billable (billable),
            INDEX idx_time_entries_billed (billed)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'organization_id' => 'INT NULL AFTER id',
        'user_id' => 'INT NULL AFTER organization_id',
        'team_member_id' => 'BIGINT UNSIGNED NULL AFTER user_id',
        'client_id' => 'INT NULL AFTER team_member_id',
        'project_id' => 'INT NULL AFTER client_id',
        'project_code' => 'VARCHAR(64) NULL AFTER project_id',
        'contract_id' => 'INT NULL AFTER project_code',
        'invoice_id' => 'INT NULL AFTER contract_id',
        'service_item_id' => 'INT NULL AFTER invoice_id',
        'description' => 'TEXT NULL AFTER service_item_id',
        'started_at' => 'DATETIME NULL AFTER description',
        'ended_at' => 'DATETIME NULL AFTER started_at',
        'hours' => 'DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER ended_at',
        'billable' => 'TINYINT(1) DEFAULT 1 AFTER hours',
        'billed' => 'TINYINT(1) DEFAULT 0 AFTER billable',
        'rate' => 'DECIMAL(10,2) DEFAULT 0 AFTER billed',
        'cost_rate_snapshot' => 'DECIMAL(12,4) NULL AFTER rate',
        'billing_rate_snapshot' => 'DECIMAL(12,4) NULL AFTER cost_rate_snapshot',
        'currency' => 'CHAR(3) NOT NULL DEFAULT "USD" AFTER billing_rate_snapshot',
        'rate_snapshot_source' => 'VARCHAR(50) NULL AFTER currency',
        'invoice_item_id' => 'INT DEFAULT NULL AFTER rate',
        'created_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER invoice_item_id',
        'updated_at' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER created_at',
    ];

    foreach ($columns as $column => $definition) {
        if (!pa_time_tracking_table_has_column($pdo, 'time_entries', $column)) {
            try {
                $pdo->exec("ALTER TABLE time_entries ADD COLUMN {$column} {$definition}");
            } catch (Throwable $e) {
                @error_log('[TimeTrackingSchema] Failed to add time_entries.' . $column . ': ' . $e->getMessage());
            }
        }
    }

    foreach ([
        'CREATE INDEX idx_time_entries_user ON time_entries (user_id)',
        'CREATE INDEX idx_time_entries_client ON time_entries (client_id)',
        'CREATE INDEX idx_time_entries_project ON time_entries (project_id)',
        'CREATE INDEX idx_time_entries_project_code ON time_entries (project_code)',
        'CREATE INDEX idx_time_entries_contract ON time_entries (contract_id)',
        'CREATE INDEX idx_time_entries_invoice ON time_entries (invoice_id)',
        'CREATE INDEX idx_time_entries_billable ON time_entries (billable)',
        'CREATE INDEX idx_time_entries_billed ON time_entries (billed)',
    ] as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            // Index likely already exists.
        }
    }

    $required = array_keys($columns);
    $missing = [];
    foreach ($required as $column) {
        if (!pa_time_tracking_table_has_column($pdo, 'time_entries', $column)) {
            $missing[] = $column;
        }
    }
    if ($missing) {
        throw new RuntimeException('time_entries schema repair incomplete; missing columns: ' . implode(', ', $missing));
    }

    $done = true;
}

function pa_time_tracking_team_member_id(PDO $pdo,int $userId): ?int
{
    try{
        $stmt=$pdo->prepare('SELECT id FROM team_members WHERE user_id=? LIMIT 1');$stmt->execute([$userId]);$id=$stmt->fetchColumn();
        if($id!==false)return (int)$id;
        $user=$pdo->prepare('SELECT COALESCE(NULLIF(username,""),email) display_name,email,is_disabled,deleted_at FROM users WHERE id=?');$user->execute([$userId]);$row=$user->fetch(PDO::FETCH_ASSOC);if(!$row)return null;
        $pdo->prepare('INSERT INTO team_members (user_id,display_name,email,is_active) VALUES (?,?,?,?)')->execute([$userId,$row['display_name'],$row['email'],empty($row['is_disabled'])&&empty($row['deleted_at'])?1:0]);return (int)$pdo->lastInsertId();
    }catch(Throwable $e){return null;}
}

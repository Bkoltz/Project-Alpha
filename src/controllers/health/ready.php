<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/api_response.php';

const PA_REQUIRED_SCHEMA_VERSION = 53;

try {
    $version = (int)$pdo->query('SELECT COALESCE(MAX(version),0) FROM schema_migrations')->fetchColumn();
    $requiredTables = [
        'mileage_charge_allocations',
        'mileage_tracking_sessions',
        'email_provider_connections',
        'addresses',
        'jobs',
        'document_revisions',
        'invoice_adjustments',
        'schedule_entries',
        'worker_documents',
        'worker_profiles',
        'work_types',
        'catalog_work_components',
        'job_work_components',
        'work_assignments',
        'pay_periods',
        'time_submissions',
        'time_submission_entries',
        'work_type_billing_defaults',
        'work_time_billing_allocations',
        'worker_earnings',
        'worker_earning_events',
        'time_correction_requests',
        'time_correction_effects',
        'time_correction_billing_resolutions',
        'worker_payment_records',
        'worker_payment_allocations',
        'payroll_exports',
        'payroll_export_rows',
        'client_credits',
        'client_credit_events',
        'catalog_link_migration_review',
        'workforce_deadline_events',
        'passkey_credentials',
        'passkey_challenges',
    ];
    $placeholders = implode(',', array_fill(0, count($requiredTables), '?'));
    $tableStmt = $pdo->prepare(
        "SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ({$placeholders})"
    );
    $tableStmt->execute($requiredTables);
    $present = array_fill_keys($tableStmt->fetchAll(PDO::FETCH_COLUMN), true);
    $missing = array_values(array_filter($requiredTables, static fn(string $table): bool => !isset($present[$table])));
    if ($version < PA_REQUIRED_SCHEMA_VERSION || $missing) {
        api_json_failure(503, 'schema_out_of_date', 'The database schema is not ready for this application version.', [
            'current_version' => $version,
            'required_version' => PA_REQUIRED_SCHEMA_VERSION,
            'missing_tables' => $missing,
        ]);
    }
    api_json_success(['status' => 'ready', 'schema_version' => $version]);
} catch (Throwable $e) {
    error_log('[readiness][' . api_request_id() . '] ' . $e->getMessage());
    api_json_failure(503, 'database_unavailable', 'The database readiness check failed.');
}

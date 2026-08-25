<?php

declare(strict_types=1);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../utils/api_response.php';

const PA_REQUIRED_SCHEMA_VERSION = 75;

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
        'pricing_adjustment_definitions',
        'project_pricing_adjustment_assignments',
        'contract_pricing_adjustment_assignments',
        'document_pricing_adjustment_overrides',
        'document_pricing_adjustment_snapshots',
        'contract_settlement_terms',
        'contract_settlements',
        'contract_settlement_lines',
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
    $requiredColumns=[
        'contracts'=>['status'],
        'project_invoices'=>['revision_number'],
        'invoices'=>['generation_key'],
        'invoice_adjustments'=>['affects_total'],
        'pricing_adjustment_definitions'=>['scope_type','scope_key','adjustment_kind','percentage_rate','is_active'],
        'document_pricing_adjustment_snapshots'=>['document_revision','source_type','currency','basis_minor','adjustment_minor','adjusted_minor','derived_from_snapshot_id'],
        'contract_settlement_terms'=>['organization_id','project_id','contract_id','contract_revision','policy_mode','commitment_end_date','target_definition_id','frozen_target_percentage'],
        'contract_settlements'=>['public_id','organization_id','project_id','contract_id','contract_revision','settlement_terms_id','request_key','basis_hash','status','actual_end_date','currency','total_delta_minor','calculation_version','basis_json','draft_invoice_id'],
        'contract_settlement_lines'=>['settlement_id','source_invoice_id','source_revision','source_pricing_snapshot_id','currency','basis_minor','historical_adjustment_minor','target_percentage_rate','target_adjustment_minor','historical_total_minor','target_total_minor','delta_minor','source_content_hash'],
    ];
    $columnStmt=$pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');
    $missingColumns=[];
    foreach($requiredColumns as $table=>$columns){
        foreach($columns as $column){
            $columnStmt->execute([$table,$column]);
            if((int)$columnStmt->fetchColumn()!==1)$missingColumns[]=$table.'.'.$column;
        }
    }
    if ($version < PA_REQUIRED_SCHEMA_VERSION || $missing || $missingColumns) {
        api_json_failure(503, 'schema_out_of_date', 'The database schema is not ready for this application version.', [
            'current_version' => $version,
            'required_version' => PA_REQUIRED_SCHEMA_VERSION,
            'missing_tables' => $missing,
            'missing_columns' => $missingColumns,
        ]);
    }
    api_json_success(['status' => 'ready', 'schema_version' => $version]);
} catch (Throwable $e) {
    error_log('[readiness][' . api_request_id() . '] ' . $e->getMessage());
    api_json_failure(503, 'database_unavailable', 'The database readiness check failed.');
}

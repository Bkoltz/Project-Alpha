SET @invoice_due_provenance_exists := (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'invoices'
      AND column_name = 'due_date_source'
);
SET @sql := IF(@invoice_due_provenance_exists = 0,
    'ALTER TABLE invoices
        ADD COLUMN payment_terms_days SMALLINT UNSIGNED NULL AFTER due_date,
        ADD COLUMN due_date_source ENUM(''terms'',''manual'') NOT NULL DEFAULT ''manual'' AFTER payment_terms_days',
    'SELECT 1');
PREPARE invoice_due_provenance_stmt FROM @sql;
EXECUTE invoice_due_provenance_stmt;
DEALLOCATE PREPARE invoice_due_provenance_stmt;

SET @default_net_terms_days := COALESCE((
    SELECT CAST(config_value AS UNSIGNED)
    FROM app_config
    WHERE organization_id = 0 AND config_key = 'net_terms_days'
    LIMIT 1
), 30);

UPDATE invoices i
LEFT JOIN projects p ON p.id = i.project_id
SET i.payment_terms_days = COALESCE(p.invoice_net_terms_days, @default_net_terms_days),
    i.due_date_source = 'terms'
WHERE i.due_date IS NOT NULL
  AND DATE(i.due_date) = DATE_ADD(
      DATE(i.document_date),
      INTERVAL COALESCE(p.invoice_net_terms_days, @default_net_terms_days) DAY
  );

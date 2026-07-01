<?php

function pa_organization_address_definitions(): array
{
    return [
        'address_line1' => "VARCHAR(255) NULL",
        'address_line2' => "VARCHAR(255) NULL",
        'city' => "VARCHAR(100) NULL",
        'state' => "VARCHAR(100) NULL",
        'postal_code' => "VARCHAR(32) NULL",
        'country' => "VARCHAR(100) NULL",
    ];
}

function pa_existing_organization_address_columns(PDO $pdo): array
{
    $names = array_keys(pa_organization_address_definitions());
    $placeholders = implode(',', array_fill(0, count($names), '?'));
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'organizations'
          AND COLUMN_NAME IN ($placeholders)
    ");
    $stmt->execute($names);

    return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
}

function pa_ensure_organization_address_columns(PDO $pdo): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $definitions = pa_organization_address_definitions();
    try {
        $existing = pa_existing_organization_address_columns($pdo);
        foreach ($definitions as $column => $definition) {
            if (!isset($existing[$column])) {
                $pdo->exec("ALTER TABLE organizations ADD COLUMN {$column} {$definition}");
            }
        }
    } catch (Throwable $e) {
        @error_log('[OrganizationSchema] Could not ensure organization address columns: ' . $e->getMessage());
    }

    try {
        $cached = pa_existing_organization_address_columns($pdo);
    } catch (Throwable $e) {
        $cached = [];
    }

    return $cached;
}

function pa_organization_address_select(PDO $pdo, string $alias = ''): string
{
    $existing = pa_ensure_organization_address_columns($pdo);
    $prefix = $alias !== '' ? $alias . '.' : '';
    $parts = [];
    foreach (array_keys(pa_organization_address_definitions()) as $column) {
        $parts[] = isset($existing[$column])
            ? "{$prefix}{$column}"
            : "NULL AS {$column}";
    }

    return implode(', ', $parts);
}

<?php

declare(strict_types=1);

// Compatibility entrypoint used by project invoice billing code. The canonical
// invoice-link policy lives in invoice_links.php.
require_once __DIR__ . '/invoice_links.php';

function pa_config_bool(array $appConfig, string $key, bool $default = false): bool
{
    if (!array_key_exists($key, $appConfig)) {
        return $default;
    }
    $value = $appConfig[$key];
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return (int)$value === 1;
    }
    return in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
}

function invoice_content_links_table_has_column(PDO $pdo, string $table, string $column): bool
{
    return pa_table_has_column($pdo, $table, $column);
}

/** @return list<array{id:int,title:?string,url:string,link_type:string,entity_type:string,entity_id:int,source_label:string}> */
function invoice_content_links_for_invoice(PDO $pdo, int $invoiceId, array $appConfig = []): array
{
    return pa_invoice_links_for_invoice($pdo, $invoiceId);
}

/** @return list<array{id:int,title:?string,url:string,link_type:string,entity_type:string,entity_id:int,source_label:string}> */
function invoice_content_links_for_project_invoice(PDO $pdo, int $projectInvoiceId, array $appConfig = [], ?array $recipientClientIds = null): array
{
    return pa_invoice_links_for_project_invoice($pdo, $projectInvoiceId, $recipientClientIds);
}

function invoice_content_links_html(array $links): string
{
    return pa_invoice_links_html($links);
}

function invoice_missing_content_links_behavior(array $appConfig): string
{
    if (!pa_config_bool($appConfig, 'invoice_content_links_enabled', false)) {
        return 'send';
    }
    $behavior = strtolower(trim((string)($appConfig['invoice_missing_content_links_behavior'] ?? 'warn')));
    return in_array($behavior, ['send', 'warn', 'block'], true) ? $behavior : 'warn';
}

function invoice_missing_content_links_message(): string
{
    return 'This invoice has no eligible content links marked for invoices.';
}

function invoice_should_prompt_for_missing_content_links(PDO $pdo, string $invoiceType, int $invoiceId, array $appConfig, ?array $recipientClientIds = null): bool
{
    if (invoice_missing_content_links_behavior($appConfig) === 'send') {
        return false;
    }
    if ($invoiceType === 'project_invoice') {
        return invoice_content_links_for_project_invoice($pdo, $invoiceId, $appConfig, $recipientClientIds) === [];
    }
    if ($invoiceType === 'invoice') {
        return invoice_content_links_for_invoice($pdo, $invoiceId, $appConfig) === [];
    }
    return false;
}

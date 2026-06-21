<?php

namespace App\data_transfer_objects\invoice;

use App\data_transfer_objects\TransferObject;

class InvoiceData extends TransferObject
{
    public ?int $id = null;
    public ?int $contract_id = null;
    public ?int $quote_id = null;
    public ?int $long_term_contract_id = null;
    public ?int $on_demand_contract_id = null;
    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?string $doc_number = null;
    public ?string $project_code = null;
    public ?string $discount_type = null;
    public ?float $discount_value = null;
    public ?float $tax_percent = null;
    public ?float $tax_amount = null;
    public ?string $tax_county = null;
    public ?float $subtotal = null;
    public ?float $total = null;
    public ?string $status = null;
    public ?string $due_date = null;
    public ?string $scheduled_date = null;
    public ?string $estimated_completion = null;
    public ?string $fulfillment_date = null;
    public ?bool $weather_pending = null;
    public ?string $scope = null;
    public ?array $custom_fields = null;
    public ?bool $is_deposit_invoice = null;
    public ?string $parent_contract_type = null;
    public ?string $created_at = null;
    public ?string $document_date = null;
    public ?string $document_date_updated_at = null;
    public ?string $notes = null;
    public ?string $billing_interval_unit = null;
    public ?int $billing_interval_count = null;
    public ?string $start_date = null;
    public ?string $end_date = null;
    public ?string $next_run_date = null;
    public ?string $last_run_date = null;
    public ?int $max_occurrences = null;
    public ?int $occurrences_generated = null;
    public ?int $proration = null;
    public ?int $anchor_day = null;
    public ?int $template_invoice_id = null;

    // On-demand invoice occurrence fields (on_demand_invoices table)
    public ?int $invoice_id = null;
    public ?int $invoice_number = null;
    public ?float $amount = null;
}

// $client_id, $discount_type, $discount_value, $tax_percent, $subtotal, $total, $estimated, $fulfillment_date, $weather, $scope,
class InvoiceEditData extends TransferObject
{
    public ?int $client_id = null;
    public ?string $discount_type = null;
    public ?float $discount_value = null;
    public ?float $tax_percent = null;
    public ?float $subtotal = null;
    public ?float $total = null;
    public ?string $estimated_completion = null;
    public ?string $fulfillment_date = null;
    public ?bool $weather_pending = null;
    public ?string $scope = null;
}

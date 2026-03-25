<?php

namespace App\data_transfer_objects;

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
    public ?array $items = null;
}

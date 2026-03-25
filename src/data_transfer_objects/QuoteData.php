<?php

namespace App\data_transfer_objects;

class QuoteData extends TransferObject
{
    public ?int $id = null;
    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?string $doc_number = null;
    public ?string $project_code = null;
    public ?string $status = null;
    public ?string $discount_type = null;
    public ?float $discount_value = null;
    public ?float $tax_percent = null;
    public ?float $subtotal = null;
    public ?float $total = null;
    public ?string $deposit_type = null;
    public ?float $deposit_amount = null;
    public ?string $fulfillment_date = null;
    public ?string $doc_type = null;
    public ?int $is_long_term = null;
    public ?int $is_on_demand = null;
    public ?string $start_date = null;
    public ?string $end_date = null;
    public ?int $billing_interval_count = null;
    public ?string $billing_interval_unit = null;
    public ?string $pricing_type = null;
    public ?float $price_per_invoice = null;
    public ?string $scope = null;
    public ?array $custom_fields = null;
    public ?string $created_at = null;
    public ?string $document_date = null;
    public ?string $document_date_updated_at = null;
    public ?string $notes = null;
    public ?string $terms = null;
}

class QuoteItemsData extends TransferObject {
    public ?array $item = null;
    public ?array $description = null;
    public ?array $quantity = null;
    public ?array $unit_price = null;
    public ?array $line_total = null;
}

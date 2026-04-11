<?php

namespace App\record_transfer_objects;

use App\data_transfer_objects\TransferObject;

class QuoteRecord extends TransferObject
{
    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?int $doc_number = null;
    public ?string $project_code = null;
    public ?string $status = null;
    public ?string $discount_type = null;
    public ?int $discount_value = null;
    public ?int $tax_percent = null;
    public ?int $subtotal = null;
    public ?int $total = null;
    public ?string $deposit_type = null;
    public ?int $deposit_amount = null;
    public ?string $fulfillment_date = null;
    public ?int $is_long_term = null;
    public ?int $is_on_demand = null;
    public ?string $start_date = null;
    public ?string $end_date = null;
    public ?int $billing_interval_count = null;
    public ?string $billing_interval_unit = null;
    public ?string $pricing_type = null;
    public ?int $price_per_invoice = null;
    public ?string $scope = null;
    public ?string $custom_fields = null;
    public ?string $created_at = null;
}

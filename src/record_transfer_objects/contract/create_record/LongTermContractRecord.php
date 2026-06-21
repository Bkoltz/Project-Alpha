<?php

namespace App\record_transfer_objects\contract\create_record;

use App\record_transfer_objects\contract\create_record\BaseContractRecord;

class LongTermContractRecord extends BaseContractRecord
{
    public ?int $quote_id = null;
    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?string $status = null;
    public ?string $discount_type = null;
    public ?float $discount_value = null;
    public ?float $tax_percent = null;
    public ?float $subtotal = null;
    public ?float $total = null;
    public ?string $project_code = null;
    public ?string $deposit_type = null;
    public ?float $deposit_value = null;
    public ?float $deposit_paid = null;
    public ?string $start_date = null;
    public ?string $end_date = null;
    public ?int $billing_interval_count = null;
    public ?string $billing_interval_unit = null;
    public ?string $pricing_type = null;
    public ?float $price_per_invoice = null;
    public ?string $scope = null;
    public ?string $next_invoice_date = null;
}

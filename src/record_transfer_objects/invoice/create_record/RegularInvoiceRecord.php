<?php

namespace App\record_transfer_objects\invoice\create_record;

class RegularInvoiceRecord extends BaseInvoiceRecord
{
    public ?int $contract_id = null;
    public ?int $quote_id = null;
    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?string $discount_type = null;
    public ?float $discount_value = null;
    public ?float $tax_percent = null;
    public ?float $subtotal = null;
    public ?float $total = null;
    public ?string $status = null;
    public ?string $due_date = null;
    public ?string $project_code = null;
    public ?string $fulfillment_date = null;
}

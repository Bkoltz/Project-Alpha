<?php

namespace App\record_transfer_objects\invoice\create_record;

class LongTermInvoiceRecord extends BaseInvoiceRecord
{
    public ?int $client_id = null;
    public ?int $project_id = null;
    public ?int $template_invoice_id = null;
    public ?string $project_code = null;
    public ?string $status = null;
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
    public ?string $discount_type = null;
    public ?float $discount_value = null;
    public ?float $tax_percent = null;
    public ?float $subtotal = null;
    public ?float $total = null;
}

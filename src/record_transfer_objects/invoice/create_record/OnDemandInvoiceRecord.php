<?php

namespace App\record_transfer_objects\invoice\create_record;

class OnDemandInvoiceRecord extends BaseInvoiceRecord
{
    public ?int $on_demand_contract_id = null;
    public ?int $invoice_id = null;
    public ?int $invoice_number = null;
    public ?float $amount = null;
    public ?string $status = null;
    public ?string $due_date = null;
    public ?string $notes = null;
}

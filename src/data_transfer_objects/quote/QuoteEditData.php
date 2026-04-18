<?php

namespace App\data_transfer_objects\quote;

use App\data_transfer_objects\TransferObject;

class QuoteEditData extends TransferObject
{
    public ?int $client_id =  null;
    public ?string $discount_type = null;
    public ?float $discount_value =  null;
    public ?float $tax_percent =  null;
    public ?float $subtotal =  null;
    public ?float $total =  null;
    public ?string $deposit_type = null;
    public ?float $deposit_amount = null;
    public ?string $fulfillment_date = null;
    public ?array $custom_fields = null;
}
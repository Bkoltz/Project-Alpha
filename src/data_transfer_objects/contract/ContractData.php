<?php

namespace App\data_transfer_objects\contract;

use App\data_transfer_objects\TransferObject;

class ContractData extends TransferObject
{
    public ?int $id = null;
    public ?int $quote_id = null;
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
    public ?bool $deposit_paid = null;
    public ?string $signed_pdf_path = null;
    public ?string $completed_at = null;
    public ?string $voided_at = null;
    public ?string $scheduled_date = null;
    public ?string $terms = null;
    public ?string $estimated_completion = null;
    public ?string $fulfillment_date = null;
    public ?bool $weather_pending = null;
    public ?string $scope = null;
    public ?array $custom_fields = null;
    public ?string $created_at = null;
    public ?string $document_date = null;
    public ?string $document_date_updated_at = null;
}

<?php

namespace App\data_transfer_objects;

class ListFilterData extends TransferObject
{
    public ?string $client_id = null;
    public ?string $client_name = null;
    public ?string $start = null;
    public ?string $end = null;
    public ?string $status = null;
    public ?string $project_code = null;
    public ?string $doc_number = null;
    public ?string $min_price = null;
    public ?string $max_price = null;
}

class DisplayCountData extends TransferObject
{
    public ?int $per_page = null;
    public ?string $page = null;
    public ?int $offset = null;
}

class PageButtonData extends TransferObject
{
    public ?string $next_page_path = null;
    public ?string $previous_page_path = null;
    public ?int $page_count = null;
}

class ListFilterConfig
{
    public ?array $filters = null;
    public ?array $documentTypeFilters = null;
}

class ListFilterStatement
{
    public string $sql = '';
    public array $values = [];
}
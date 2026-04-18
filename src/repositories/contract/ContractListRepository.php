<?php

namespace App\repositories\contract;

use App\data_transfer_objects\ListFilterStatement;
use App\data_transfer_objects\DisplayCountData;
use App\data_transfer_objects\ListFilterConfig;
use App\data_transfer_objects\ListFilterData;
use App\repositories\BaseListRepository;
use App\utils\enum\DocumentType;
use App\render_outputs\contract\ContractListView;
use PDO;

class ContractListRepository extends BaseListRepository
{
    private PDO $pdo;

    private const FILTERS = [
        'client_id' => [
            'sql' => 'co.client_id = ?',
            'ignore' => ''
        ],
        'client_name' => [
            'sql' => 'c.name LIKE ?',
            'ignore' => ''
        ],
        'start' => [
            'sql' => 'co.created_at >= ?',
            'ignore' => ''
        ],
        'end' => [
            'sql' => 'co.created_at <= ?',
            'ignore' => ''
        ],
        'status' => [
            'sql' => 'co.status = ?',
            'ignore' => 'all'
        ],
        'project_code' => [
            'sql' => 'co.project_code LIKE ?',
            'ignore' => ''
        ],
        'doc_number' => [
            'sql' => 'co.doc_number=?',
            'ignore' => ''
        ],
        'min_price' => [
            'sql' => 'co.total>=?',
            'ignore' => ''
        ],
        'max_price' => [
            'sql' => 'co.total<=?',
            'ignore' => ''
        ],
    ];

    private const DOCUMENT_TYPE_FILTERS = [
        'regular' => '',
        'on_demand' => '(COALESCE(co.is_long_term, 0) = 0 AND co.is_on_demand = 1)',
        'long_term' => '(co.is_long_term = 1 AND COALESCE(co.is_on_demand, 0) = 0)',
    ];

    private const DOCUMENT_TYPE_STATEMENTS = [
        'regular' => 'SELECT co.id, co.doc_number, co.project_code, co.status, co.total, co.deposit_type, co.deposit_amount, co.deposit_paid, co.signed_pdf_path, c.name client_name, c.id AS client_id FROM contracts co JOIN clients c ON c.id=co.client_id',
        'on_demand' => 'SELECT co.id, co.doc_number, co.project_code, co.status, co.start_date, co.end_date, co.billing_interval_count, co.billing_interval_unit, co.price_per_invoice, co.total_invoiced, co.invoice_count, co.last_invoice_date, c.name client_name, c.id AS client_id FROM on_demand_contracts co LEFT JOIN clients c ON c.id=co.client_id',
        'long_term' => 'SELECT co.id, co.doc_number, co.project_code, co.status, co.total, co.deposit_type, co.deposit_amount, co.deposit_paid, co.start_date, co.end_date, co.billing_interval_count, co.billing_interval_unit, co.pricing_type, co.price_per_invoice, co.total_invoiced, co.next_invoice_date, c.name client_name, c.id AS client_id FROM long_term_contracts co LEFT JOIN clients c ON c.id=co.client_id',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getContractRows(DocumentType $documentType, ListFilterData $filterData, DisplayCountData $pageCountData): array
    {
        $filterConfig = new ListFilterConfig($this::FILTERS, $this::DOCUMENT_TYPE_FILTERS);
        $filterStatement = $this->createFilteredStatement($documentType, $filterData, $filterConfig);

        $sql = $this::DOCUMENT_TYPE_STATEMENTS[$documentType->value];
        $sql .= $filterStatement->sql;
        $sql .= " ORDER BY co.created_at DESC LIMIT $pageCountData->per_page OFFSET $pageCountData->offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($filterStatement->values);

        return $stmt->fetchAll();
    }

    public function getContractCount(DocumentType $documentType, ListFilterData $filterData): int
    {
        $filterConfig = new ListFilterConfig($this::FILTERS, $this::DOCUMENT_TYPE_FILTERS);
        $filterStatement = $this->createFilteredStatement($documentType, $filterData, $filterConfig);

        $sqlCount = 'SELECT COUNT(*) FROM contracts co' . $filterStatement->sql;
        $stc = $this->pdo->prepare($sqlCount);
        $stc->execute($filterStatement->values);

        return (int)$stc->fetchColumn();
    }
}

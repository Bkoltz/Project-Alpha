<?php

namespace App\repositories\invoice;

use App\data_transfer_objects\DisplayCountData;
use App\data_transfer_objects\ListFilterConfig;
use App\data_transfer_objects\ListFilterData;
use App\repositories\BaseListRepository;
use App\utils\enum\DocumentType;
use PDO;

class InvoiceListRepository extends BaseListRepository
{
    private PDO $pdo;

    private const FILTERS = [
        'client_id' => [
            'sql' => 'i.client_id = ?',
            'ignore' => ''
        ],
        'client_name' => [
            'sql' => 'i.name LIKE ?',
            'ignore' => ''
        ],
        'start' => [
            'sql' => 'i.created_at >= ?',
            'ignore' => ''
        ],
        'end' => [
            'sql' => 'i.created_at <= ?',
            'ignore' => ''
        ],
        'status' => [
            'sql' => 'i.status = ?',
            'ignore' => 'all'
        ],
        'project_code' => [
            'sql' => 'i.project_code LIKE ?',
            'ignore' => ''
        ],
        'doc_number' => [
            'sql' => 'i.doc_number=?',
            'ignore' => ''
        ],
        'min_price' => [
            'sql' => 'i.total>=?',
            'ignore' => ''
        ],
        'max_price' => [
            'sql' => 'i.total<=?',
            'ignore' => ''
        ],
    ];

    private const DOCUMENT_TYPE_FILTERS = [
        'regular' => '',
        'on_demand' => '(COALESCE(i.is_long_term, 0) = 0 AND i.is_on_demand = 1)',
        'long_term' => '(i.is_long_term = 1 AND COALESCE(i.is_on_demand, 0) = 0)',
    ];

    private const DOCUMENT_TYPE_STATEMENTS = [
        'regular' => 'SELECT i.id,i.doc_number,i.project_code,i.total,i.status,i.created_at,i.due_date,c.name client,c.id AS client_id FROM invoices i JOIN clients c ON c.id=i.client_id',
        'on_demand' => 'SELECT i.id, i.doc_number, i.project_code, i.status, i.total, i.due_date, i.on_demand_contract_id, i.created_at, c.name client, c.id AS client_id, odc.doc_number AS contract_doc_number FROM invoices i LEFT JOIN clients c ON c.id=i.client_id LEFT JOIN on_demand_contracts odc ON odc.id=i.on_demand_contract_id',
        'long_term' => 'SELECT i.id, i.doc_number, i.project_code, i.status, i.billing_interval_count, i.billing_interval_unit, i.pricing_type, i.price_per_invoice, i.total, i.total_invoiced, i.next_invoice_date, i.last_invoice_date, i.start_date, i.end_date, c.name client_name, c.id AS client_id FROM long_term_contracts i LEFT JOIN clients c ON c.id=i.client_id',
    ];


    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getInvoiceRows(DocumentType $documentType, ListFilterData $filterData, DisplayCountData $pageCountData): array
    {
        $filterConfig = new ListFilterConfig($this::FILTERS);
        $filterStatement = $this->createFilteredStatement($documentType, $filterData, $filterConfig);

        $sql = $this::DOCUMENT_TYPE_STATEMENTS[$documentType->value];
        $sql .= $filterStatement->sql;
        $sql .= " ORDER BY i.created_at DESC LIMIT $pageCountData->per_page OFFSET $pageCountData->offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($filterStatement->values);

        return $stmt->fetchAll();
    }

    public function getInvoiceCount(DocumentType $documentType, ListFilterData $filterData): int
    {
        $filterConfig = new ListFilterConfig($this::FILTERS, $this::DOCUMENT_TYPE_FILTERS);
        $filterStatement = $this->createFilteredStatement($documentType, $filterData, $filterConfig);

        $sqlCount = 'SELECT COUNT(*) FROM invoices i' . $filterStatement->sql;
        $stc = $this->pdo->prepare($sqlCount);
        $stc->execute($filterStatement->values);

        return (int)$stc->fetchColumn();
    }
}

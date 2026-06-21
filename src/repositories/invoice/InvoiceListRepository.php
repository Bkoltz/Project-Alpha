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
        // 'client_id'/'start'/'end' are added by getFilters(), keyed to the document type's actual columns.
        'client_name' => [
            'sql' => 'c.name LIKE ?',
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

    private const DOCUMENT_TYPE_STATEMENTS = [
        DocumentType::REGULAR->value => 'SELECT i.id,i.doc_number,i.project_code,i.total,i.status,i.created_at,i.due_date,c.name client,c.id AS client_id FROM invoices i JOIN clients c ON c.id=i.client_id',
        DocumentType::LONG_TERM->value => 'SELECT i.id, i.doc_number, i.project_code, i.status, i.billing_interval_count, i.billing_interval_unit, i.pricing_type, i.price_per_invoice, i.total, i.total_invoiced, i.next_invoice_date, i.last_invoice_date, i.start_date, i.end_date, c.name client_name, c.id AS client_id FROM long_term_contracts i LEFT JOIN clients c ON c.id=i.client_id',
        DocumentType::ON_DEMAND->value => 'SELECT i.id, i.on_demand_contract_id, i.amount, i.due_date, i.generated_at, c.name AS client_name, c.id AS client_id FROM on_demand_invoices i LEFT JOIN on_demand_contracts odc ON odc.id=i.on_demand_contract_id LEFT JOIN clients c ON c.id=odc.client_id',
    ];

    // Each entry joins clients (and, for on-demand, on_demand_contracts) so that
    // client_id/client_name filters resolve to real columns in the count query too.
    private const DOCUMENT_TYPE_TABLES = [
        DocumentType::REGULAR->value => "invoices i LEFT JOIN clients c ON c.id=i.client_id",
        DocumentType::LONG_TERM->value => "long_term_invoices i LEFT JOIN clients c ON c.id=i.client_id",
        DocumentType::ON_DEMAND->value => "on_demand_invoices i LEFT JOIN on_demand_contracts odc ON odc.id=i.on_demand_contract_id LEFT JOIN clients c ON c.id=odc.client_id",
    ];

    // on_demand_invoices has no created_at column - it tracks generated_at instead.
    private const DOCUMENT_TYPE_ORDER_COLUMN = [
        DocumentType::REGULAR->value => 'created_at',
        DocumentType::LONG_TERM->value => 'created_at',
        DocumentType::ON_DEMAND->value => 'generated_at',
    ];

    // on_demand_invoices has no client_id column directly - it's on the joined on_demand_contracts row.
    private const DOCUMENT_TYPE_CLIENT_ID_COLUMN = [
        DocumentType::REGULAR->value => 'i.client_id',
        DocumentType::LONG_TERM->value => 'i.client_id',
        DocumentType::ON_DEMAND->value => 'odc.client_id',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getInvoiceRows(DocumentType $documentType, ListFilterData $filterData, DisplayCountData $pageCountData): array
    {
        $orderColumn = $this::DOCUMENT_TYPE_ORDER_COLUMN[$documentType->value];
        $filterConfig = new ListFilterConfig($this->getFilters($documentType));
        $filterStatement = $this->createFilteredStatement($documentType, $filterData, $filterConfig);

        $sql = $this::DOCUMENT_TYPE_STATEMENTS[$documentType->value];
        $sql .= $filterStatement->sql;
        $sql .= " ORDER BY i.$orderColumn DESC LIMIT $pageCountData->per_page OFFSET $pageCountData->offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($filterStatement->values);

        return $stmt->fetchAll();
    }

    public function getInvoiceCount(DocumentType $documentType, ListFilterData $filterData): int
    {
        $filterConfig = new ListFilterConfig($this->getFilters($documentType));
        $table = $this::DOCUMENT_TYPE_TABLES[$documentType->value];
        $filterStatement = $this->createFilteredStatement($documentType, $filterData, $filterConfig);

        $sqlCount = 'SELECT COUNT(*) FROM ' . $table . $filterStatement->sql;
        $stc = $this->pdo->prepare($sqlCount);
        $stc->execute($filterStatement->values);

        return (int)$stc->fetchColumn();
    }

    // 'start'/'end'/'client_id' need a different source column per document type:
    // on_demand_invoices has no created_at or client_id - it uses generated_at and the
    // joined on_demand_contracts.client_id instead.
    private function getFilters(DocumentType $documentType): array
    {
        $dateColumn = $this::DOCUMENT_TYPE_ORDER_COLUMN[$documentType->value];
        $clientIdColumn = $this::DOCUMENT_TYPE_CLIENT_ID_COLUMN[$documentType->value];

        $filters = $this::FILTERS;
        $filters['start'] = ['sql' => "i.$dateColumn >= ?", 'ignore' => ''];
        $filters['end'] = ['sql' => "i.$dateColumn <= ?", 'ignore' => ''];
        $filters['client_id'] = ['sql' => "$clientIdColumn = ?", 'ignore' => ''];

        return $filters;
    }
}

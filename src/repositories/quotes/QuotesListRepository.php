<?php

namespace App\repositories\quotes;

use App\data_transfer_objects\DisplayCountData;
use App\data_transfer_objects\ListFilterConfig;
use App\data_transfer_objects\ListFilterData;
use App\repositories\BaseListRepository;
use PDO;
use App\utils\enum\DocumentType;

class QuotesListRepository extends BaseListRepository
{
    private PDO $pdo;

    private const FILTERS = [
        'clientId' => [
            'sql' => 'q.client_id = ?',
            'ignore' => ''
        ],
        'clientName' => [
            'sql' => 'c.name LIKE ?',
            'ignore' => ''
        ],
        'start' => [
            'sql' => 'q.created_at >= ?',
            'ignore' => ''
        ],
        'end' => [
            'sql' => 'q.created_at <= ?',
            'ignore' => ''
        ],
        'status' => [
            'sql' => 'q.status = ?',
            'ignore' => 'all'
        ],
        'projectCode' => [
            'sql' => 'q.project_code LIKE ?',
            'ignore' => ''
        ],
    ];

    private const DOCUMENT_TYPE_FILTERS = [
        DocumentType::REGULAR->value => '(COALESCE(q.is_long_term, 0) = 0 AND COALESCE(q.is_on_demand, 0) = 0)',
        DocumentType::ON_DEMAND->value => '(COALESCE(q.is_long_term, 0) = 0 AND q.is_on_demand = 1)',
        DocumentType::LONG_TERM->value => '(q.is_long_term = 1 AND COALESCE(q.is_on_demand, 0) = 0)',
    ];

    private const DOCUMENT_TYPE_VALUES = [
        DocumentType::REGULAR->value =>['q.id', 'q.status', 'q.total', 'q.created_at', 'c.name AS client_name', 'c.id AS client_id', 'q.doc_number', 'q.project_code'],
        DocumentType::ON_DEMAND->value => ['q.id', 'q.doc_number', 'q.project_code', 'q.status', 'q.total', 'q.start_date', 'q.end_date', 'q.price_per_invoice', 'q.created_at', 'c.name AS client_name', 'c.id AS client_id'],
        DocumentType::LONG_TERM->value => ['q.id', 'q.doc_number', 'q.project_code', 'q.status', 'q.total', 'q.created_at', 'q.start_date', 'q.end_date', 'q.billing_interval_count', 'q.billing_interval_unit', 'c.name AS client_name', 'c.id AS client_id']
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getQuoteRows(DocumentType $documentType, ListFilterData $filterData, DisplayCountData $pageCountData): array
    {
        $filterConfig = new ListFilterConfig([
            'filters' => self::FILTERS,
            'document_type_filters' => self::DOCUMENT_TYPE_FILTERS
        ]);

        $filterStatement = $this->createFilteredStatement($documentType, $filterData, $filterConfig);

        $columns = $this::DOCUMENT_TYPE_VALUES[$documentType->value]; 
        $select = implode(', ', $columns);

        $sql = "SELECT $select FROM quotes q JOIN clients c ON c.id=q.client_id";
        $sql .= $filterStatement->sql;
        $sql .= " ORDER BY q.created_at DESC LIMIT $pageCountData->per_page OFFSET $pageCountData->offset";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($filterStatement->values);

        return $stmt->fetchAll();
    }

    public function getQuoteCount(DocumentType $documentType, ListFilterData $filterData): int
    {
        $filterConfig = new ListFilterConfig([
            'filters' => self::FILTERS,
            'document_type_filters' => self::DOCUMENT_TYPE_FILTERS
        ]);

        $filterStatement = $this->createFilteredStatement($documentType, $filterData, $filterConfig);

       
        $sqlCount = 'SELECT COUNT(*) FROM quotes q' . $filterStatement->sql;
        $stc = $this->pdo->prepare($sqlCount);
        $stc->execute($filterStatement->values);

        return (int)$stc->fetchColumn();
    }
}

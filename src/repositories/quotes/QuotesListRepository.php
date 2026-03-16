<?php

namespace App\repositories\quotes;

use PDO;

class QuotesListRepository
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
        'onDemand' => '(COALESCE(q.is_long_term, 0) = 0 AND q.is_on_demand = 1)',
        'longTerm' => '(q.is_long_term = 1 AND COALESCE(q.is_on_demand, 0) = 0)',
    ];

    private const DOCUMENT_TYPE_VALUES = [
        'onDemand' => ['q.id', 'q.doc_number', 'q.project_code', 'q.status', 'q.total', 'q.start_date', 'q.end_date', 'q.price_per_invoice', 'q.created_at', 'c.name AS client_name', 'c.id AS client_id'],
        'longTerm' => ['q.id', 'q.doc_number', 'q.project_code', 'q.status', 'q.total', 'q.created_at', 'q.start_date', 'q.end_date', 'q.billing_interval_count', 'q.billing_interval_unit', 'c.name AS client_name', 'c.id AS client_id']
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getDisplayData(string $documentType, array $filterValues, array $countData): array
    {
        $filter = $this->createFilteredStatement($documentType, $filterValues);

        $quoteRows = $this->getRawQuotes($documentType, $filter, $countData['perPage'], $countData['offset']);
        $totalQuotes = $this->getTotalQuotes($filter);

        return ['rows' => $quoteRows, 'quotesCount' => $totalQuotes];
    }

    public function getClient(int $clientId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function createFilteredStatement(string $documentType, array $filterValues): array
    {
        $where[] = self::DOCUMENT_TYPE_FILTERS[$documentType] ?? '((q.is_long_term = 0 OR q.is_long_term IS NULL) AND (q.is_on_demand = 0 OR q.is_on_demand IS NULL))'; // Default to regular quotes becuase I dont know
        $values = [];

        foreach ($filterValues as $key => $value) {
            if (!isset(self::FILTERS[$key]))
                continue;

            $filter = self::FILTERS[$key];

            if ($value === $filter['ignore'] || empty($value))
                continue;

            $where[] = $filter['sql'];
            $values[] = $value;
        }

        $where = ' WHERE ' . implode(' AND ', $where);

        return ['statement' => $where, 'values' => $values];
    }

    private function getRawQuotes(string $documentType, array $filter, int $amount, int $offset): array
    {
        $columns = $this::DOCUMENT_TYPE_VALUES[$documentType] ?? ['q.id', 'q.status', 'q.total', 'q.created_at', 'c.name AS client_name', 'c.id AS client_id', 'q.doc_number', 'q.project_code']; // Same thing here, default to regular quotes if it aint found
        $select = implode(', ', $columns);

        $sql = "SELECT $select FROM quotes q JOIN clients c ON c.id=q.client_id";
        $sql .= $filter['statement'];
        $sql .= " ORDER BY q.created_at DESC LIMIT $amount OFFSET $offset";
        $st = $this->pdo->prepare($sql);

        $st->execute($filter['values']);

        return $st->fetchAll();
    }

    private function getTotalQuotes(array $filter): int
    {
        $sqlCount = 'SELECT COUNT(*) FROM quotes q' . $filter['statement'];

        $stc = $this->pdo->prepare($sqlCount);
        $stc->execute($filter['values']);

        return (int)$stc->fetchColumn();
    }
}

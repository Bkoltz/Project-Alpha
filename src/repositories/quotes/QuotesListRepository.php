<?php

namespace App\repositories\quotes;

use PDO;

class QuotesListRepository
{
    private PDO $pdo;

    private static array $FilterStatements = [
        'clientId' => 'q.client_id=?',
        'clientName' => 'c.name LIKE ?',
        'start' => 'q.created_at>=?',
        'end'  => 'q.created_at<=?',
        'status' => 'q.status=?',
        'projectCode' => 'q.project_code LIKE ?',
        'docNo'  => 'q.doc_number=?',
        'minPrice' => 'q.total>=?',
        'maxPrice' => 'q.total<=?',
    ];

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function getDisplayData(array $filterValues, array $countData): array
    {
        $filter = $this->createFilteredStatement($filterValues);

        $statement = $filter['statement'];
        $values = $filter['values'];

        $quoteRows = $this->getRawQuotes($statement, $values, $countData['perPage'], $countData['offset']);
        $totalQuotes = $this->getTotalQuotes($statement, $values);

        return ['rows' => $quoteRows, 'totalQuotes' => $totalQuotes];
    }

    public function hasProject(): bool
    {
        return (bool)$this->pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME='project_code'")->fetchColumn();
    }

    public function hasDocument(): bool
    {
        return (bool)$this->pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='quotes' AND COLUMN_NAME='doc_number'")->fetchColumn();
    }

    private function createFilteredStatement(array $filterValues): array
    {
        $where = ['(q.is_long_term IS NULL OR q.is_long_term=0)'];
        $values = [];

        foreach ($filterValues as [$key, $value]) {
            if (array_key_exists($key, $filterValues)) {
                $where[] = QuotesListRepository::$FilterStatements[$key];
                $values[] = $value;
            }
        }

        $where = ' WHERE ' . implode(' AND ', $where);
        return ['statement' => $where, 'values' => $values];
    }

    private function getRawQuotes(string $statement, array $values, int $amount, int $offset): array
    {
        $hasDocument = $this->hasDocument();
        $hasProject = $this->hasProject();

        $columns = ['q.id', 'q.status', 'q.total', 'q.created_at', 'c.name AS client_name', 'c.id AS client_id'];
        $columns[] = $hasDocument ? 'q.doc_number' : 'q.id AS doc_number';
        $columns[] = $hasProject ? 'q.project_code' : "'' AS project_code";
        $select = implode(', ', $columns);

        $sql = "SELECT $select FROM quotes q JOIN clients c ON c.id=q.client_id";
        $sql .= $statement;
        $sql .= " ORDER BY q.created_at DESC LIMIT $amount OFFSET $offset";

        $st = $this->pdo->prepare($sql);
        $st->execute($values);

        return $st->fetchAll();
    }

    private function getTotalQuotes(string $statement, array $values): int
    {
        $sqlCount = 'SELECT COUNT(*) FROM quotes q' . $statement;

        $stc = $this->pdo->prepare($sqlCount);
        $stc->execute($values);

        return (int)$stc->fetchColumn();
    }
}

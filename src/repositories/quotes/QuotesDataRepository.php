<?php

namespace App\repositories\quotes;

use Exception;
use PDO;

require_once BASE_PATH . '/src/utils/project_id.php';

class QuotesDataRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createQuote($pageData)
    {
        $id = $this->addQuote($pageData);

        $this->updateDocumentNumber($id, $pageData['is_long_term'], $pageData['is_on_demand']);

        $this->setQuoteItems($pageData['items'], $id);

        $this->setQuoteNotes($pageData['project_code'], $pageData['client_id'], $pageData['notes'], $pageData['terms']);
    }

    public function editQuote(array $pageData, int $id)
    {
        $this->updateQuote($pageData, $id);

        $this->setQuoteItems($pageData['items'], $id);

        $this->setQuoteNotes($pageData['project_code'], $pageData['client_id'], $pageData['notes'], $pageData['terms']);
    }

    private function updateQuote($pageData, $id)
    {
        $sortedQuoteData = $this->sortUpdateQuoteData($pageData);
        $sortedQuoteData[] = $id;

        $quote = $this->pdo->prepare('UPDATE quotes SET client_id = ?, discount_type = ?, discount_value = ?, tax_percent = ?, subtotal = ?, total = ?, deposit_type = ?, deposit_amount = ?, fulfillment_date = ?, scope = ?, custom_fields = ? WHERE id = ?');

        $quote->execute($sortedQuoteData);
    }

    public function clientExists(int $clientId): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM clients WHERE id = ? LIMIT 1');
        $stmt->execute([$clientId]);
        return (bool) $stmt->fetchColumn();
    }

    public function getClientById(int $clientId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    private function addQuote(array $pageData): int
    {
        $sortedQuoteData = $this->sortAddQuoteData($pageData);

        $stmt = $this->pdo->prepare('INSERT INTO quotes (client_id, project_id, doc_number, project_code, status, discount_type, discount_value, tax_percent, subtotal, total, deposit_type, deposit_amount, fulfillment_date, is_long_term, is_on_demand, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope, custom_fields, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute($sortedQuoteData);

        return (int)$this->pdo->lastInsertId();
    }

    private function setQuoteItems(array $items, $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM quote_items WHERE quote_id = ?');
        $stmt->execute([$id]);

        $qi = $this->pdo->prepare('INSERT INTO quote_items (quote_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');

        foreach ($items as $it) {
            $qi->execute([$id, $it['item'], $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
        }
    }

    private function sortUpdateQuoteData($pageData): array
    {
        return [
            $pageData['client_id'],
            $pageData['discount_type'],
            $pageData['discount_value'],
            $pageData['tax_percent'],
            $pageData['subtotal'],
            $pageData['total'],
            $pageData['deposit_type'],
            $pageData['deposit_amount'],
            $pageData['fulfillment_date'],
            $pageData['scope'],
            $pageData['custom_fields'],
        ];
    }

    private function sortAddQuoteData($pageData): array
    {
        return [
            $pageData['client_id'],
            null,
            null,
            $pageData['project_code'],
            'pending',
            $pageData['discount_type'],
            $pageData['discount_value'],
            $pageData['tax_percent'],
            $pageData['subtotal'],
            $pageData['total'],
            $pageData['deposit_type'],
            $pageData['deposit_value'],
            $pageData['fulfillment_date'],
            $pageData['is_long_term'],
            $pageData['is_on_demand'],
            $pageData['start_date'],
            $pageData['end_date'],
            $pageData['billing_interval_count'],
            $pageData['billing_interval_unit'],
            $pageData['pricing_type'],
            $pageData['price_per_invoice'],
            $pageData['scope'],
            $pageData['custom_fields'],
            date("Y-m-d H:i:s")
        ];
    }

    public function getCustomFields(string $documentType): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM document_custom_fields WHERE document_type = ? AND is_enabled = 1 ORDER BY display_order, id');
        $stmt->execute([$documentType]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getNextProjectCode(float $client_id): string
    {
        return project_next_code($this->pdo, $client_id);
    }

    private function setQuoteNotes(string $projectCode, string $clientId, string $notes, string $terms = ''): void
    {
        $up = $this->pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes, terms) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes), terms=VALUES(terms)');
        $up->execute([$projectCode, $clientId, $notes, $terms]);
    }


    private function updateDocumentNumber(int $id, bool $is_long_term, bool $is_on_demand): void
    {
        if ($is_on_demand) {
            $qMax = (int)$this->pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE is_on_demand=1')->fetchColumn();
        } elseif ($is_long_term) {
            $qMax = (int)$this->pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE is_long_term=1 AND is_on_demand=0')->fetchColumn();
        } else {
            $qMax = (int)$this->pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE is_long_term=0 AND is_on_demand=0')->fetchColumn();
        }

        $stmt = $this->pdo->prepare('UPDATE quotes SET doc_number=? WHERE id=?');
        $stmt->execute([$qMax + 1, $id]);
    }

    private function addProjectDocuments(int $projectId, int $quoteId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "quote", ?)');
        $stmt->execute([$projectId, $quoteId]);
    }

    public function getQuote(int $id): array
    {
        $quote = $this->pdo->prepare('SELECT * FROM quotes WHERE id=?');
        $quote->execute([$id]);
        return $quote->fetch(PDO::FETCH_ASSOC);
    }

    public function getQuoteMeta(string $projectCode): array
    {
        $pm = $this->pdo->prepare('SELECT notes, terms FROM project_meta WHERE project_code=?');
        $pm->execute([$projectCode]);
        return $pm->fetch(PDO::FETCH_ASSOC);
    }

    public function getQuoteItems(int $id): array
    {
        $items = $this->pdo->prepare('SELECT * FROM quote_items WHERE quote_id=?');
        $items->execute([$id]);
        return $items->fetchAll(PDO::FETCH_ASSOC);
    }
}

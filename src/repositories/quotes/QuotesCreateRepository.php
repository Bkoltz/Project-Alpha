<?php

namespace App\repositories\quotes;

use PDO;

require_once BASE_PATH . '/src/utils/project_id.php';

class QuotesCreateRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createQuote($pageData){
        $this->addQuote($pageData);

        $this->addItems($pageData['items']);

        $this->addQuoteNotes($pageData['project_code'], $pageData['client_id'], $pageData['notes']);
    }

    private function addQuote(array $pageData): void
    {
        $sortedQuoteData = $this->sortQuoteData($pageData);

        error_log(json_encode($sortedQuoteData));

        $stmt = $this->pdo->prepare('INSERT INTO quotes (client_id, project_id, doc_number, project_code, status, discount_type, discount_value, tax_percent, subtotal, total, deposit_type, deposit_amount, fulfillment_date, is_long_term, is_on_demand, start_date, end_date, billing_interval_count, billing_interval_unit, pricing_type, price_per_invoice, scope, custom_fields, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute($sortedQuoteData);

        // $this->updateDocumentNumber($pageData['is_long_term'], $pageData['is_on_demand']);
    }

    private function sortQuoteData($pageData): array
    {
        return [
            $pageData['client_id'],
            $pageData['project_id'],
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

    public function getClients(): array
    {
        $clients = $this->pdo->query("SELECT id, name FROM clients ORDER BY name ASC");
        return $clients->fetchAll();
    }

    public function getNextProjectCode(float $client_id): string
    {
        return project_next_code($this->pdo, $client_id);
    }

    private function addQuoteNotes(string $projectCode, string $clientId, string $notes): void
    {
        $up = $this->pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes) VALUES (?,?,?) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes)');
        $up->execute([$projectCode, $clientId, $notes]);
    }

    private function updateDocumentNumber(bool $is_long_term, bool $is_on_demand): void
    {
        if ($is_on_demand) {
            $qMax = (int)$this->pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE is_on_demand=1')->fetchColumn();
        } elseif ($is_long_term) {
            $qMax = (int)$this->pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE is_long_term=1 AND is_on_demand=0')->fetchColumn();
        } else {
            $qMax = (int)$this->pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE is_long_term=0 AND is_on_demand=0')->fetchColumn();
        }

        $quoteId = (int)$this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('UPDATE quotes SET doc_number=? WHERE id=?');
        $stmt->execute([$qMax++, $quoteId]);
    }

    private function addItems(array $items): void
    {
        $quoteId = (int)$this->pdo->lastInsertId();
        $qi = $this->pdo->prepare('INSERT INTO quote_items (quote_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');

        foreach ($items as $it) {
            $qi->execute([$quoteId, $it['item'], $it['description'], $it['quantity'], $it['unit_price'], $it['line_total']]);
        }
    }

    private function addProjectDocuments(int $projectId, int $quoteId): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO project_documents (project_id, document_type, document_id) VALUES (?, "quote", ?)');
        $stmt->execute([$projectId, $quoteId]);
    }
}

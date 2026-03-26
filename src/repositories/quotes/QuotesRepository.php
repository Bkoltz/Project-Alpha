<?php

namespace App\repositories\quotes;

use App\record_transfer_objects\ItemRecord;
use App\record_transfer_objects\QuoteMetaRecord;
use App\record_transfer_objects\QuoteRecord;
use PDO;

require_once BASE_PATH . '/src/utils/csrf.php';

class QuotesRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createNewQuote(QuoteRecord $quoteData, QuoteMetaRecord $quoteMeta, ItemRecord $quoteItems)
    {
        $id = $this->insertNewQuote($quoteData);
        $this->setQuoteItems($id, $quoteItems);
        $this->updateDocumentNumber($id, $quoteData);
        $this->setQuoteNotes($quoteMeta);
    }

    public function editStoredQuote(int $id, QuoteRecord $quoteData, QuoteMetaRecord $quoteMeta, ItemRecord $quoteItems)
    {
        // $this->updateStoredQuote($id, $quoteData);
        $this->setQuoteItems($id, $quoteItems);
        $this->setQuoteNotes($quoteMeta);
    }

    public function insertNewQuote(QuoteRecord $quoteData): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO quotes (client_id, project_id, doc_number, project_code, status,discount_type, discount_value, tax_percent, subtotal, total,deposit_type, deposit_amount, fulfillment_date,is_long_term, is_on_demand,start_date, end_date,billing_interval_count, billing_interval_unit,pricing_type, price_per_invoice,scope, custom_fields, created_at) VALUES (:client_id, :project_id, :doc_number, :project_code, :status,:discount_type, :discount_value, :tax_percent, :subtotal, :total,:deposit_type, :deposit_amount, :fulfillment_date,:is_long_term, :is_on_demand,:start_date, :end_date,:billing_interval_count, :billing_interval_unit,:pricing_type, :price_per_invoice,:scope, :custom_fields, :created_at)");
        $stmt->execute($quoteData->toArray());
        
        return (int)$this->pdo->lastInsertId();
    }

    public function updateStoredQuote(int $id, array $quoteData)
    {
        $quoteData[] = $id;
        $quote = $this->pdo->prepare('UPDATE quotes SET client_id = ?, discount_type = ?, discount_value = ?, tax_percent = ?, subtotal = ?, total = ?, deposit_type = ?, deposit_amount = ?, fulfillment_date = ?, scope = ?, custom_fields = ? WHERE id = ?');

        $quote->execute($quoteData);
    }

    //This function is reusable for editing and creation of new quotes
    private function setQuoteItems(int $id, ItemRecord $quoteItems)
    {
        //Remove old items if they exist
        $stmt = $this->pdo->prepare('DELETE FROM quote_items WHERE quote_id = ?');
        $stmt->execute([$id]);

        $qi = $this->pdo->prepare('INSERT INTO quote_items (quote_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');

        for ($i = 0; $i < count($quoteItems->item); $i++) {
            $row = array_merge([$id], $quoteItems->getRow($i));
            $qi->execute($row);
        }
    }

    private function updateDocumentNumber(int $id, QuoteRecord $quoteData) :void {
        if ($quoteData->is_on_demand) {
            $qMax = (int)$this->pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE is_on_demand=1')->fetchColumn();
        } elseif ($quoteData->is_long_term) {
            $qMax = (int)$this->pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE is_long_term=1 AND is_on_demand=0')->fetchColumn();
        } else {
            $qMax = (int)$this->pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM quotes WHERE is_long_term=0 AND is_on_demand=0')->fetchColumn();
        }

        $stmt = $this->pdo->prepare('UPDATE quotes SET doc_number=? WHERE id=?');
        $stmt->execute([$qMax + 1, $id]);
    }

    private function setQuoteNotes(QuoteMetaRecord $metaRecord): void
    {
        $up = $this->pdo->prepare('INSERT INTO project_meta (project_code, client_id, notes, terms) VALUES (:project_code, :client_id, :notes, :terms) ON DUPLICATE KEY UPDATE client_id=VALUES(client_id), notes=VALUES(notes), terms=VALUES(terms)');
        $up->execute($metaRecord->toArray());
    }

    public function approveQuote(int $id): void
    {
        $stmt = $this->pdo->prepare('UPDATE quotes SET status="approved" WHERE id=?');
        $stmt->execute([$id]);
    }

    public function rejectQuote(int $id)
    {
        $st = $this->pdo->prepare('UPDATE quotes SET status="rejected" WHERE id=? AND status="pending"');
        $st->execute([$id]);
    }

    public function getQuoteData(int $id) : QuoteRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM quotes WHERE id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        return QuoteRecord::fromArray($data);
    }

    public function getQuoteItems(int $id) : ItemRecord {
        $stmt = $this->pdo->prepare('SELECT * FROM quote_items WHERE quote_id = ?');
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        return ItemRecord::fromArray($data);
    }

    public function getNextProjectCode(int $clientId): string
    {
        return project_next_code($this->pdo, $clientId);
    }
}

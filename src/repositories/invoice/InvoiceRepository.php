<?php

namespace App\repositories\invoice;

use App\record_transfer_objects\InvoiceRecord;
use App\record_transfer_objects\ItemRecord;
use App\record_transfer_objects\InvoiceEditRecord;
use App\record_transfer_objects\MetaRecord;
use PDO;

require_once BASE_PATH . '/src/utils/csrf.php';


class InvoiceRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createInvoice(InvoiceRecord $invoiceData, ItemRecord $invoiceItems): void
    {
        $id = $this->insertInvoice($invoiceData);
        $this->insertInvoiceItems($id, $invoiceItems);
        $this->updateInvoiceDocNumber($id);
    }

    public function updateFullInvoice(int $id, InvoiceEditRecord $invoiceData, ItemRecord $invoiceItems): void
    {
        $this->updateInvoice($id, $invoiceData);
        $this->updateInvoiceItems($id, $invoiceItems);
    }

    public function updateInvoice(int $id, InvoiceEditRecord $invoiceData): void
    {
        $stmt = $this->pdo->prepare('UPDATE invoices SET client_id=?, discount_type=?, discount_value=?, tax_percent=?, subtotal=?, total=?, estimated_completion=?, fulfillment_date=?, weather_pending=?, scope=? WHERE contract_id=?');
        $stmt->execute(array_merge($invoiceData->toArray(), [$id]));
    }

    private function insertInvoice(InvoiceRecord $invoiceData): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO invoices (contract_id, quote_id, client_id, project_id, discount_type, discount_value, tax_percent, subtotal, total, status, due_date, project_code, fulfillment_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute($invoiceData->toNumericArray());

        return (int)$this->pdo->lastInsertId();
    }

    public function voidInvoice(int $id): void
    {
        $this->pdo->prepare("UPDATE invoices SET status='void' WHERE contract_id=?")->execute([$id]);
    }

    public function getInvoiceIdsFromContract(int $contractId): array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM invoices WHERE contract_id=?');
        $stmt->execute([$contractId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function updateInvoiceItems(int $id, ItemRecord $invoiceItems): void
    {
        $this->pdo->prepare('DELETE FROM invoice_items WHERE invoice_id=?')->execute([$id]);
        $this->insertInvoiceItems($id, $invoiceItems);
    }

    public function insertInvoiceItems(int $id, ItemRecord $invoiceItems)
    {
        $stmt = $this->pdo->prepare('INSERT INTO invoice_items (invoice_id, item, description, quantity, unit_price, line_total) VALUES (?,?,?,?,?,?)');

        for ($i = 0; $i < 0; $i++) {
            $row = array_merge([$id], $invoiceItems->getRow($i));
            $stmt->execute($row);
        }
    }

    public function doesInvoiceExist(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT EXISTS (SELECT 1 FROM invoices WHERE contract_id = :id)');
        $stmt->execute(['id' => $id]);

        return (bool) $stmt->fetchColumn();
    }

    public function payDeposit(int $id, float $depositAmount): void
    {
        $this->pdo->prepare('UPDATE invoices SET total = total - ?, status = "partial" WHERE contract_id = ? AND status = "unpaid"')->execute([$depositAmount, $id]);
    }

    public function getStoredInvoice(int $id): InvoiceRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoices WHERE id=?');
        $stmt->execute([$id]);
        return InvoiceRecord::fromArray($stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function getStoredInvoiceItems(int $id): ItemRecord
    {
        $stmt = $this->pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id=?');
        $stmt->execute([$id]);
        return ItemRecord::fromArray($stmt->fetch(PDO::FETCH_ASSOC));
    }

    public function getDueDateFromContractId(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT due_date FROM invoices WHERE contract_id=? ORDER BY id DESC LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function denyInvoice(int $id): void
    {
        $this->pdo->prepare("UPDATE invoices SET status='denied' WHERE contract_id=? AND status<>'paid'")->execute([$id]);
    }

    public function setInvoiceDueDate(int $id, string $dueDate): void
    {
        $this->pdo->prepare('UPDATE invoices SET due_date=? WHERE id=?')->execute([$dueDate, $id]);
    }

    public function getProjectCode(int $clientId): string
    {
        return project_next_code($this->pdo, $clientId);
    }

    public function updateInvoiceDocNumber(int $id): void
    {
        $count = (int)$this->pdo->query('SELECT COALESCE(MAX(doc_number),0) FROM invoices')->fetchColumn();
        $this->pdo->prepare('UPDATE invoices SET doc_number=? WHERE id=?')->execute([$count + 1, $id]);
    }
}
